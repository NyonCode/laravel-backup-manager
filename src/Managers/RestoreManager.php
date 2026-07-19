<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Archive\Archiver;
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

    public function restore(StoredBackup $backup, RestoreOptions $options): RestoreResult
    {
        $manifest = $this->repository->readManifest($backup);
        $this->assertAuthentic($manifest, $options);
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

                $restoredFiles = $options->restoreFiles
                    ? $this->restoreFiles($manifest, $extractDir, $options)
                    : 0;
            } catch (Throwable $exception) {
                if ($point !== null) {
                    $this->guard->rollback($point);

                    throw RestoreException::failed(
                        'restore failed and was rolled back to the pre-restore state: '.$exception->getMessage(),
                        $exception,
                    );
                }

                throw $exception;
            }

            return new RestoreResult($backup->name, $restoredDatabases, $restoredFiles, $manifest);
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
        $this->encryptors->for($manifest->encryption)->decrypt($downloaded, $decrypted);

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

    private function restoreFiles(Manifest $manifest, string $extractDir, RestoreOptions $options): int
    {
        $count = 0;

        foreach ($manifest->files as $file) {
            if (! ArchivePath::isFileEntry($file->path)) {
                continue;
            }

            $absolute = ArchivePath::toAbsolute($file->path);

            if ($options->isSelective() && ! $this->matchesSelection($absolute, $options->onlyPaths)) {
                continue;
            }

            $this->restoreSingleFile($file, $absolute, $extractDir);
            $count++;
        }

        return $count;
    }

    private function restoreSingleFile(ManifestFile $file, string $absolute, string $extractDir): void
    {
        $this->ensureDirectory(dirname($absolute));

        if ($file->isSymlink) {
            if (! is_link($absolute) && $file->symlinkTarget !== null) {
                @symlink($file->symlinkTarget, $absolute);
            }

            return;
        }

        $source = rtrim($extractDir, '/').'/'.$file->path;

        if (! is_file($source)) {
            throw RestoreException::failed("Missing file [{$file->path}] in archive.");
        }

        // Write to a temporary file and atomically rename into place, so a crash
        // mid-write never leaves a half-written file at the target path.
        $temp = $absolute.'.bm-restore-tmp';

        if (! copy($source, $temp) || ! rename($temp, $absolute)) {
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
