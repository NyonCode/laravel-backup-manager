<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Archive\Archiver;
use Nyoncode\BackupManager\Contracts\Database\LocksDownClient;
use Nyoncode\BackupManager\Contracts\Integrity\Signer;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Exceptions\RestoreException;
use Nyoncode\BackupManager\Support\ArchivePath;
use Nyoncode\BackupManager\Support\RestoreGuard;
use Nyoncode\BackupManager\Support\SafePath;
use Nyoncode\BackupManager\Support\Workspace;
use Nyoncode\BackupManager\ValueObjects\DatabaseArtifact;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;
use Nyoncode\BackupManager\ValueObjects\RestoreResult;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;
use Throwable;

/**
 * Restores a stored backup: verifies integrity, decrypts, decompresses,
 * extracts and reinstates databases and/or files.
 *
 * The restore target is the *current* environment — database connections are
 * resolved from live config, never from the archived values — so a backup can
 * be restored into staging or a fresh host safely.
 */
final class RestoreManager
{
    private const FILES_PREFIX = 'files/';

    public function __construct(
        private readonly Repository $config,
        private readonly BackupRepository $repository,
        private readonly EncryptorManager $encryptors,
        private readonly CompressorManager $compressors,
        private readonly DatabaseManager $databases,
        private readonly Archiver $archiver,
        private readonly RestoreGuard $guard,
        private readonly SnapshotDriver $snapshots,
        private readonly ?Signer $signer = null,
    ) {}

    /**
     * @param  string|null  $profile  The profile the caller is restoring for — the
     *                                trusted side. When given, a manifest naming another
     *                                profile is refused, and the include paths that confine
     *                                the restore are this profile's, never the manifest's
     *                                choice. Null falls back to the profile the manifest names.
     */
    public function restore(StoredBackup $backup, RestoreOptions $options, ?string $profile = null): RestoreResult
    {
        $manifest = $this->repository->readManifest($backup);
        $this->assertAuthentic($manifest, $options);

        $profile = $this->profileFor($manifest, $profile);

        // A dump nothing authenticates goes only through a client that will
        // not run its commands — checked before anything is downloaded.
        if ($options->restoreDatabases) {
            $this->assertDumpsCanBeRestoredSafely($manifest, $options);
        }

        // Before anything is downloaded, decrypted or touched: every file must
        // land inside what the profile backs up. Paths come from the manifest —
        // plaintext and, without signing, unauthenticated — so this is what
        // stops a tampered one from writing to ~/.ssh or /etc.
        if ($options->restoreFiles) {
            $this->assertWithinIncludePaths($manifest, $options, $profile);
        }

        $workspace = Workspace::create($this->workingDirectory(), 'restore');

        try {
            $extractDir = $workspace->path('extract');

            // Both modes converge on the same extract directory: an archive is
            // unpacked into it, while a snapshot materialises its blobs into it.
            // Everything downstream (rollback, database and file restore) then
            // runs identically regardless of how the backup was stored.
            if ($manifest->mode->isIncremental()) {
                $this->snapshots->materialize($backup, $manifest, $extractDir, $options);
            } else {
                $tar = $this->prepareArchive($backup, $manifest, $workspace, $options);
                $this->archiver->extractPaths($tar, $this->prefixes($options), $extractDir);
            }

            // Snapshot the current state so a mid-restore failure can be undone.
            $point = $options->safe
                ? $this->guard->capture(
                    $manifest,
                    $options,
                    $workspace->path('rollback'),
                    fn (string $connection) => $this->resolveTargetOrNull($connection),
                )
                : null;

            try {
                $restoredDatabases = $options->restoreDatabases
                    ? $this->restoreDatabases($manifest, $extractDir)
                    : [];

                $skippedLinks = [];
                $restoredFiles = $options->restoreFiles
                    ? $this->restoreFiles($manifest, $extractDir, $options, $profile, $skippedLinks)
                    : 0;
            } catch (Throwable $exception) {
                if ($point !== null) {
                    $failures = $this->guard->rollback($point);

                    throw RestoreException::failed(
                        ($failures === []
                            ? 'restore failed and was rolled back to the pre-restore state: '
                            : 'restore failed and the rollback was INCOMPLETE ('.implode('; ', $failures).'); check these by hand. Original error: ')
                        .$exception->getMessage(),
                        $exception,
                    );
                }

                throw $exception;
            }

            return new RestoreResult($backup->name, $restoredDatabases, $restoredFiles, $manifest, $skippedLinks);
        } finally {
            $workspace->delete();
        }
    }

    /**
     * Refuse to restore a backup we cannot prove is authentic.
     *
     * When this environment has signing configured (a Signer is bound) and the
     * caller requested integrity checks, the manifest MUST carry a signature that
     * verifies. A missing signature — e.g. one an attacker stripped to dodge the
     * check — or an invalid one fails closed. Environments without signing keep
     * the checksum-only behaviour, so unsigned backups still restore there.
     */
    private function assertAuthentic(Manifest $manifest, RestoreOptions $options): void
    {
        if (! $options->verifyChecksums || $this->signer === null) {
            return;
        }

        $signature = $manifest->signature;

        if ($signature === null || ! $this->signer->verify($manifest->withoutSignature()->toJson(), $signature)) {
            throw IntegrityException::signatureMismatch();
        }
    }

    private function prepareArchive(
        StoredBackup $backup,
        Manifest $manifest,
        Workspace $workspace,
        RestoreOptions $options,
    ): string {
        $downloaded = $workspace->path('archive');
        $this->repository->download($backup, $downloaded);

        if ($options->verifyChecksums && ! $manifest->archiveChecksum->matchesFile($downloaded)) {
            throw IntegrityException::checksumMismatch(
                $backup->name,
                (string) $manifest->archiveChecksum,
                (string) $manifest->archiveChecksum->algorithm->value,
            );
        }

        $decrypted = $workspace->path('decrypted');
        // The pre-1.3 CBC format carries no tag, so a modified archive in it
        // decrypts into modified plaintext. Accepted only when something else
        // authenticates the backup — a verified signature — or the operator
        // says so. The manifest's own schema version cannot decide this: an
        // unsigned manifest is exactly what an attacker rewrites.
        $this->encryptors
            ->for($manifest->encryption, allowLegacyCbc: self::legacyCbcAllowed($this->config, $this->authenticated($manifest, $options)))
            ->decrypt($downloaded, $decrypted);

        $tar = $workspace->path('archive.tar');
        $this->compressors->for($manifest->compression)->decompress($decrypted, $tar);

        return $tar;
    }

    /**
     * @return list<string>
     */
    private function prefixes(RestoreOptions $options): array
    {
        $prefixes = [];

        if ($options->restoreDatabases) {
            $prefixes[] = 'databases/';
        }

        if ($options->restoreFiles) {
            $prefixes[] = self::FILES_PREFIX;
        }

        return $prefixes;
    }

    /**
     * @return list<string>
     */
    private function restoreDatabases(Manifest $manifest, string $extractDir): array
    {
        $restored = [];

        foreach ($manifest->databases as $artifact) {
            // The dump path comes from the manifest — untrusted data. Resolve it
            // inside the extract dir so a hostile `archivedPath` (e.g. `../../etc`)
            // cannot make us read and pipe an arbitrary host file into the client.
            $dumpPath = SafePath::resolveWithin($extractDir, $artifact->archivedPath);

            if (! is_file($dumpPath)) {
                throw RestoreException::failed("Missing database dump [{$artifact->archivedPath}] in archive.");
            }

            $target = $this->resolveTarget($artifact);
            $this->databases->restorer($target->driver)->restore($target, $dumpPath);

            $restored[] = $artifact->connection;
        }

        return $restored;
    }

    /**
     * Regular files first, symbolic links last — so no file of this restore is
     * ever written through a link this restore created.
     *
     * @param  list<string>  $skippedLinks  Filled with every link not recreated.
     */
    private function restoreFiles(Manifest $manifest, string $extractDir, RestoreOptions $options, ?string $profile, array &$skippedLinks): int
    {
        $count = 0;
        $roots = $this->includeRoots($profile);
        $links = [];

        foreach ($manifest->files as $file) {
            if (! ArchivePath::isFileEntry($file->path)) {
                continue;
            }

            // Collapsed before anything compares against it: `--only /var/www/app`
            // must not match `/var/www/app/../../../etc/x`, which is where that
            // path actually lands.
            $absolute = SafePath::lexical(ArchivePath::toAbsolute($file->path));

            if ($options->isSelective() && ! $this->matchesSelection($absolute, $options->onlyPaths)) {
                continue;
            }

            if ($file->isSymlink) {
                $links[$absolute] = $file;

                continue;
            }

            $this->restoreRegularFile($file, $absolute, $extractDir, $roots);
            $count++;
        }

        foreach ($links as $absolute => $file) {
            if ($this->restoreLink($file, $absolute, $roots)) {
                $count++;
            } else {
                $skippedLinks[] = $absolute;
            }
        }

        return $count;
    }

    /**
     * @param  list<string>  $roots  The profile's include roots, physical.
     */
    private function restoreLink(ManifestFile $file, string $absolute, array $roots): bool
    {
        $this->ensureDirectory(dirname($absolute));

        if (is_link($absolute) || $file->symlinkTarget === null) {
            return true;
        }

        // The target is read from the manifest, which is plaintext and —
        // without signing — unauthenticated. A link to `/` planted in a web
        // root would publish the whole host, so a link is only recreated when
        // its target, resolved through whatever already exists on this host,
        // stays inside what the profile backs up.
        if ($this->confined() && ! $this->inside($this->physical($this->linkTarget($absolute, $file->symlinkTarget)), $roots)) {
            logger()->warning(sprintf(
                'Backup Manager restore skipped the symbolic link [%s] -> [%s]: its target lies outside the profile\'s include paths.',
                $absolute,
                $file->symlinkTarget,
            ));

            return false;
        }

        $created = @symlink($file->symlinkTarget, $absolute);

        // The check above resolves the target lexically, then through what
        // exists; the kernel walks it component by component, following each
        // link before applying `..` (`s -> .` makes `s/../x` leave the root).
        // So the link, once made, is resolved the kernel's way and removed if
        // it lands outside — or if it cannot be resolved and climbs with `..`.
        if ($created && $this->confined()) {
            $real = realpath($absolute);
            $climbs = preg_match('#(^|/)\.\.(/|$)#', str_replace('\\', '/', $file->symlinkTarget)) === 1;

            $outside = is_string($real)
                ? ! $this->inside(rtrim(str_replace('\\', '/', $real), '/') ?: '/', $roots)
                : $climbs;

            if ($outside) {
                @unlink($absolute);
                logger()->warning(sprintf(
                    'Backup Manager restore removed the symbolic link [%s] -> [%s]: it resolves outside the profile\'s include paths.',
                    $absolute,
                    $file->symlinkTarget,
                ));

                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $roots  The profile's include roots, physical.
     */
    private function restoreRegularFile(ManifestFile $file, string $absolute, string $extractDir, array $roots): void
    {
        $this->ensureDirectory(dirname($absolute));

        // Checked again at the moment of writing, through what exists now: the
        // up-front check cannot see a directory that became a link since.
        if ($this->confined() && ! $this->inside($this->location($absolute), $roots)) {
            throw RestoreException::outsideIncludePaths([$absolute]);
        }

        // Resolved inside the extract directory: a manifest path may not
        // reach out of it to read some other file on this host.
        $source = SafePath::resolveWithin($extractDir, $file->path);

        if (! is_file($source)) {
            throw RestoreException::failed("Missing file [{$file->path}] in archive.");
        }

        // Write to a temporary file and atomically rename into place, so a crash
        // mid-write never leaves a half-written file at the target path.
        $temp = $absolute.'.bm-restore-tmp';

        if (! copy($source, $temp)) {
            @unlink($temp);

            throw RestoreException::failed("Failed to restore file to [{$absolute}].");
        }

        // Permissions go on before the file is in place, so it is never
        // visible at its real path with wider access than it was backed up with.
        if ($file->mode !== null) {
            @chmod($temp, $file->mode);
        }

        if (! rename($temp, $absolute)) {
            @unlink($temp);

            throw RestoreException::failed("Failed to restore file to [{$absolute}].");
        }
    }

    private function resolveTarget(DatabaseArtifact $artifact): DatabaseTarget
    {
        return $this->resolveTargetOrNull($artifact->connection)
            ?? throw RestoreException::failed(
                "Connection [{$artifact->connection}] is not configured in this environment.",
            );
    }

    private function resolveTargetOrNull(string $connection): ?DatabaseTarget
    {
        /** @var array<string, mixed>|null $connectionConfig */
        $connectionConfig = $this->config->get("database.connections.{$connection}");

        return is_array($connectionConfig)
            ? DatabaseTarget::fromConnection($connection, $connectionConfig)
            : null;
    }

    private function assertWithinIncludePaths(Manifest $manifest, RestoreOptions $options, ?string $profile): void
    {
        if (! $this->confined()) {
            return;
        }

        $roots = $this->includeRoots($profile);
        $known = $profile !== null && is_array($this->config->get("backup-manager.profiles.{$profile}"));
        $outside = [];

        foreach ($manifest->files as $file) {
            if (! ArchivePath::isFileEntry($file->path)) {
                continue;
            }

            $absolute = SafePath::lexical(ArchivePath::toAbsolute($file->path));

            if ($options->isSelective() && ! $this->matchesSelection($absolute, $options->onlyPaths)) {
                continue;
            }

            if (! $this->inside($this->location($absolute), $roots)) {
                $outside[] = $absolute;
            }
        }

        if ($outside !== []) {
            throw RestoreException::outsideIncludePaths($outside, $known ? null : ($profile ?? ''));
        }
    }

    /**
     * The profile whose include paths confine this restore: the caller's, when
     * it says — and then the manifest must agree — or else the manifest's.
     */
    private function profileFor(Manifest $manifest, ?string $expected): ?string
    {
        $recorded = $manifest->config['profile'] ?? null;
        $recorded = is_string($recorded) && $recorded !== '' ? $recorded : null;

        if ($expected !== null && $recorded !== null && $recorded !== $expected) {
            throw RestoreException::profileMismatch($recorded, $expected);
        }

        return $expected ?? $recorded;
    }

    /**
     * Refuse, before anything changes, to feed an unauthenticated dump to a
     * client that would run the commands in it.
     *
     * A backup is authenticated when this installation signs and the
     * manifest's signature verified (assertAuthentic has just checked it).
     * Otherwise each database's client must lock itself down — MariaDB
     * `--sandbox`, MySQL `--commands=OFF`, psql `\restrict` — unless the
     * operator has said, for this restore, that they trust the dump.
     */
    private function assertDumpsCanBeRestoredSafely(Manifest $manifest, RestoreOptions $options): void
    {
        if ($this->authenticated($manifest, $options) || (bool) $this->config->get('backup-manager.restore.trust_unsigned_dumps', false)) {
            return;
        }

        foreach ($manifest->databases as $artifact) {
            $target = $this->resolveTargetOrNull($artifact->connection);

            if ($target === null) {
                continue; // reported by the restore itself
            }

            $restorer = $this->databases->restorer($target->driver);

            if ($restorer instanceof LocksDownClient && ! $restorer->locksDownClient($target->driver)) {
                throw RestoreException::untrustedDump($artifact->connection, $target->driver->value);
            }
        }
    }

    /**
     * Whether this backup is authenticated: this installation signs, the
     * caller asked for verification, and the manifest carries a signature —
     * which assertAuthentic() has already checked.
     */
    private function authenticated(Manifest $manifest, RestoreOptions $options): bool
    {
        return $this->signer !== null && $options->verifyChecksums && $manifest->signature !== null;
    }

    /**
     * Whether the unauthenticated pre-1.3 CBC format may be decrypted: for an
     * authenticated backup, or when the operator opted in.
     */
    public static function legacyCbcAllowed(Repository $config, bool $authenticated): bool
    {
        return $authenticated || (bool) $config->get('backup-manager.encryption.allow_legacy_cbc', false);
    }

    private function confined(): bool
    {
        return (bool) $this->config->get('backup-manager.restore.confine_to_include_paths', true);
    }

    /**
     * The include roots of a profile as configured *now* — the configuration
     * is trusted, the manifest is not — each resolved to its physical path.
     *
     * @return list<string>
     */
    private function includeRoots(?string $profile): array
    {
        if ($profile === null) {
            return [];
        }

        $roots = [];

        foreach ((array) $this->config->get("backup-manager.profiles.{$profile}.files.include", []) as $root) {
            if (is_string($root) && $root !== '') {
                // Windows include paths use backslashes; archive paths never do.
                $roots[] = $this->physical(SafePath::lexical(str_replace('\\', '/', $root)));
            }
        }

        return array_values(array_unique($roots));
    }

    /**
     * Whether a physical path lies inside one of the roots. `/` contains
     * every absolute path — trimmed it would be empty and match nothing.
     *
     * @param  list<string>  $roots
     */
    private function inside(string $path, array $roots): bool
    {
        foreach ($roots as $root) {
            $root = rtrim($root, '/');

            if ($root === '' ? str_starts_with($path, '/') : ($path === $root || str_starts_with($path, $root.'/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Where writing to $path actually lands: its directory resolved through
     * every symlink that exists, its own name kept — a rename replaces a link
     * at that name rather than following it.
     */
    private function location(string $path): string
    {
        return rtrim($this->physical(dirname($path)), '/').'/'.basename($path);
    }

    private function linkTarget(string $link, string $target): string
    {
        $target = str_replace('\\', '/', $target);
        $absolute = str_starts_with($target, '/') || preg_match('#^[A-Za-z]:/#', $target) === 1;

        return SafePath::lexical($absolute ? $target : dirname($link).'/'.$target);
    }

    /**
     * A path with its deepest existing ancestor resolved through realpath(),
     * and the part that does not exist yet appended unchanged. A dangling link
     * on the way cannot be resolved, so the result matches no root.
     */
    private function physical(string $path): string
    {
        $suffix = '';
        $current = $path;

        while ($current !== '' && $current !== '/' && ! file_exists($current) && ! is_link($current)) {
            $suffix = '/'.basename($current).$suffix;
            $current = dirname($current);
        }

        $real = realpath($current === '' ? '/' : $current);

        if (! is_string($real)) {
            return "\0unresolvable";
        }

        return rtrim(str_replace('\\', '/', $real), '/').$suffix;
    }

    /**
     * @param  list<string>  $selection
     */
    private function matchesSelection(string $absolute, array $selection): bool
    {
        foreach ($selection as $prefix) {
            $normalized = rtrim($prefix, '/');

            if ($absolute === $normalized || str_starts_with($absolute, $normalized.'/')) {
                return true;
            }
        }

        return false;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw RestoreException::failed("Unable to create directory [{$directory}].");
        }
    }

    private function workingDirectory(): string
    {
        return (string) $this->config->get(
            'backup-manager.working_directory',
            sys_get_temp_dir().'/backup-manager',
        );
    }
}
