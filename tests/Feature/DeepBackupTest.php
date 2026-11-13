<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Encryption\OpenSslEncryptor;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Exceptions\RestoreException;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\DatabaseManager;
use Nyoncode\BackupManager\Managers\EncryptorManager;
use Nyoncode\BackupManager\Managers\RestoreManager;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Support\EncryptionKey;
use Nyoncode\BackupManager\Support\RestoreGuard;
use Nyoncode\BackupManager\Support\RestorePoint;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-deep-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    $this->dbFile = "{$this->work}/database.sqlite";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/app.php", 'return ["app"];');
    touch($this->dbFile);

    config()->set('database.connections.bm_sqlite', [
        'driver' => 'sqlite', 'database' => $this->dbFile, 'prefix' => '', 'foreign_key_constraints' => false,
    ]);
    config()->set('backup-manager.default', 'test');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'none');
    config()->set('backup-manager.profiles', [
        'test' => [
            'name' => 'Acme', 'type' => 'full',
            'databases' => ['connections' => ['bm_sqlite']],
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'], 'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
    DB::connection('bm_sqlite')->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    DB::connection('bm_sqlite')->statement("INSERT INTO items (name) VALUES ('one'),('two')");
});

function archivesOn(string $disk): array
{
    return array_values(array_filter(
        Storage::disk($disk)->files('backups'),
        fn ($f) => ! str_ends_with($f, '.manifest.json'),
    ));
}

it('writes the backup to every destination disk', function (): void {
    Storage::fake('secondary');
    config()->set('backup-manager.profiles.test.destinations', ['local', 'secondary']);

    app(BackupManager::class)->run();

    expect(archivesOn('local'))->toHaveCount(1)
        ->and(archivesOn('secondary'))->toHaveCount(1)
        ->and(Storage::disk('secondary')->files('backups'))->toHaveCount(2); // archive + manifest
});

it('creates distinct filenames for two backups even within the same second', function (): void {
    $a = app(BackupManager::class)->run();
    $b = app(BackupManager::class)->run();

    expect($a->archiveFileName)->not->toBe($b->archiveFileName)
        ->and(archivesOn('local'))->toHaveCount(2);
});

it('backs up files only, skipping databases', function (): void {
    config()->set('backup-manager.profiles.test.type', 'files');

    $manifest = app(BackupManager::class)->run()->manifest;

    expect($manifest->databases)->toBeEmpty()
        ->and($manifest->fileCount())->toBeGreaterThan(0);
});

it('backs up databases only, skipping files', function (): void {
    config()->set('backup-manager.profiles.test.type', 'database');

    $manifest = app(BackupManager::class)->run()->manifest;

    expect($manifest->databases)->toHaveCount(1)
        // only the database dump is present, no application files
        ->and(collect($manifest->files)->every(fn ($f) => str_starts_with($f->path, 'databases/')))->toBeTrue();
});

it('round-trips through bzip2 + AES-256-CBC', function (): void {
    config()->set('backup-manager.compression.algorithm', 'bzip2');
    config()->set('backup-manager.encryption.algorithm', 'aes-256-cbc');
    config()->set('backup-manager.encryption.key', 'base64:'.base64_encode(random_bytes(32)));

    app(BackupManager::class)->run();
    DB::connection('bm_sqlite')->statement('DROP TABLE items');

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::full());
    DB::purge('bm_sqlite');

    expect(DB::connection('bm_sqlite')->table('items')->count())->toBe(2);
});

it('round-trips through zstd compression', function (): void {
    if (! (new BinaryLocator)->has('zstd')) {
        $this->markTestSkipped('zstd binary not available');
    }
    config()->set('backup-manager.compression.algorithm', 'zstd');

    $result = app(BackupManager::class)->run();
    expect($result->archiveFileName)->toEndWith('.tar.zst');

    DB::connection('bm_sqlite')->statement('DROP TABLE items');
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::full());
    DB::purge('bm_sqlite');

    expect(DB::connection('bm_sqlite')->table('items')->count())->toBe(2);
});

it('preserves and restores unicode and spaced filenames', function (): void {
    file_put_contents("{$this->filesDir}/účtenka 2026 – žluťoučký.txt", 'český obsah');
    config()->set('backup-manager.profiles.test.type', 'files');

    app(BackupManager::class)->run();
    unlink("{$this->filesDir}/účtenka 2026 – žluťoučký.txt");

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(file_get_contents("{$this->filesDir}/účtenka 2026 – žluťoučký.txt"))->toBe('český obsah');
});

it('recreates a symlink from manifest metadata on restore', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlink creation requires elevated privileges on Windows');
    }

    file_put_contents("{$this->filesDir}/real.txt", 'target contents');
    symlink("{$this->filesDir}/real.txt", "{$this->filesDir}/link.txt");
    config()->set('backup-manager.profiles.test.type', 'files');

    app(BackupManager::class)->run();
    unlink("{$this->filesDir}/link.txt");
    expect(is_link("{$this->filesDir}/link.txt"))->toBeFalse();

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(is_link("{$this->filesDir}/link.txt"))->toBeTrue()
        ->and(readlink("{$this->filesDir}/link.txt"))->toBe("{$this->filesDir}/real.txt");
});

it('keeps per-file checksums that match the restored files', function (): void {
    for ($i = 0; $i < 150; $i++) {
        file_put_contents("{$this->filesDir}/file-{$i}.dat", random_bytes(256 + $i));
    }
    config()->set('backup-manager.profiles.test.type', 'files');

    $manifest = app(BackupManager::class)->run()->manifest;
    expect($manifest->fileCount())->toBeGreaterThanOrEqual(151);

    // Every recorded checksum matches the real source file.
    foreach ($manifest->files as $file) {
        $absolute = '/'.substr($file->path, strlen('files/'));
        if (is_file($absolute) && ! $file->isSymlink) {
            expect($file->checksum->matchesFile($absolute))->toBeTrue();
        }
    }
});

it('restores only a selected sub-directory', function (): void {
    mkdir("{$this->filesDir}/keep", 0777, true);
    mkdir("{$this->filesDir}/other", 0777, true);
    file_put_contents("{$this->filesDir}/keep/a.txt", 'A');
    file_put_contents("{$this->filesDir}/other/b.txt", 'B');
    config()->set('backup-manager.profiles.test.type', 'files');

    app(BackupManager::class)->run();
    file_put_contents("{$this->filesDir}/keep/a.txt", 'CORRUPT');
    file_put_contents("{$this->filesDir}/other/b.txt", 'CORRUPT');

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::selective(["{$this->filesDir}/keep"]));

    expect(file_get_contents("{$this->filesDir}/keep/a.txt"))->toBe('A')          // restored
        ->and(file_get_contents("{$this->filesDir}/other/b.txt"))->toBe('CORRUPT'); // untouched
});

it('fails closed when signing is enabled without the Pro signer', function (): void {
    // Signing is a Pro capability. In the free core, enabling it must abort
    // loudly rather than silently produce an unsigned backup a user believes is
    // signed. (The Pro suite covers the happy path and tamper detection.)
    config()->set('backup-manager.integrity.signing.enabled', true);
    config()->set('backup-manager.integrity.signing.key', 'super-secret-signing-key');

    expect(fn () => app(BackupManager::class)->run())
        ->toThrow(IntegrityException::class);
});

it('refuses to recreate a symlink that points outside the profile on restore', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlink creation requires elevated privileges on Windows');
    }

    // A link out of the backed-up tree — what a tampered manifest would plant
    // in a web root (`public/r -> /`) to publish the whole host.
    symlink('/', "{$this->filesDir}/escape");
    config()->set('backup-manager.profiles.test.type', 'files');

    app(BackupManager::class)->run();
    unlink("{$this->filesDir}/escape");

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(is_link("{$this->filesDir}/escape"))->toBeFalse()
        ->and(file_get_contents("{$this->filesDir}/app.php"))->toBe('return ["app"];');
});

it('aborts a restore before touching anything when the rollback snapshot cannot be taken', function (): void {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        $this->markTestSkipped('root ignores file permissions');
    }

    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    // The live database cannot be read, so it cannot be saved for rollback —
    // a timeout or a full disk would look the same to the guard.
    file_put_contents("{$this->filesDir}/app.php", 'changed since the backup');
    chmod($this->dbFile, 0000);

    try {
        expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::full()))
            ->toThrow(RestoreException::class, 'could not be saved for rollback');
    } finally {
        chmod($this->dbFile, 0644);
    }

    // Nothing was restored: the file still holds what it held before.
    expect(file_get_contents("{$this->filesDir}/app.php"))->toBe('changed since the backup');
});

it('restores a file with the permissions it was backed up with', function (): void {
    file_put_contents("{$this->filesDir}/.env", 'APP_KEY=secret');
    chmod("{$this->filesDir}/.env", 0600);
    config()->set('backup-manager.profiles.test.type', 'files');

    $manifest = app(BackupManager::class)->run()->manifest;
    $entry = collect($manifest->files)->first(fn ($f) => str_ends_with($f->path, '/.env'));
    expect($entry?->mode)->toBe(0600);

    unlink("{$this->filesDir}/.env");
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    clearstatcache();
    expect(fileperms("{$this->filesDir}/.env") & 0777)->toBe(0600);
});

it('keeps an old manifest without modes byte-identical when re-serialised', function (): void {
    $file = ManifestFile::fromArray([
        'path' => 'files/a', 'size' => 1,
        'checksum' => ['algorithm' => 'sha256', 'digest' => str_repeat('a', 64)],
        'is_symlink' => false, 'symlink_target' => null,
    ]);

    expect($file->toArray())->not->toHaveKey('mode');
});

it('refuses a manifest that aims a file outside the profile, before changing anything', function (): void {
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    // Tamper with the plaintext sidecar: point app.php somewhere it never was.
    $outside = sys_get_temp_dir().'/bm-outside-'.uniqid().'/authorized_keys';
    $json = json_decode((string) Storage::disk('local')->get($backup->manifestPath), true);
    foreach ($json['files'] as &$entry) {
        if (str_ends_with($entry['path'], '/app.php')) {
            $entry['path'] = 'files'.$outside;
        }
    }
    unset($entry);
    Storage::disk('local')->put($backup->manifestPath, (string) json_encode($json));

    file_put_contents("{$this->filesDir}/app.php", 'live');

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly()))
        ->toThrow(RestoreException::class, 'outside the profile');

    expect(is_file($outside))->toBeFalse()
        ->and(file_get_contents("{$this->filesDir}/app.php"))->toBe('live');
});

it('restores to recorded paths outside the profile only when told to', function (): void {
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    // The app "moved": the profile now points elsewhere.
    config()->set('backup-manager.profiles.test.files.include', [sys_get_temp_dir().'/bm-moved-'.uniqid()]);
    unlink("{$this->filesDir}/app.php");

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly()))
        ->toThrow(RestoreException::class);

    config()->set('backup-manager.restore.confine_to_include_paths', false);
    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(file_get_contents("{$this->filesDir}/app.php"))->toBe('return ["app"];');
});

it('restores when the profile includes the root directory', function (): void {
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    config()->set('backup-manager.profiles.test.files.include', ['/']);
    unlink("{$this->filesDir}/app.php");

    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(file_get_contents("{$this->filesDir}/app.php"))->toBe('return ["app"];');
});

it('accepts a root reached through a symlink by its resolved path', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlinks');
    }

    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();   // recorded under the real path
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    $current = "{$this->work}/current";
    symlink($this->filesDir, $current);  // the profile now names the symlink
    config()->set('backup-manager.profiles.test.files.include', [$current]);
    unlink("{$this->filesDir}/app.php");

    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(file_get_contents("{$this->filesDir}/app.php"))->toBe('return ["app"];');
});

it('names an unknown profile when it refuses to restore', function (): void {
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    config()->set('backup-manager.profiles', ['other' => config('backup-manager.profiles.test')]);

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly()))
        ->toThrow(RestoreException::class, 'profile [test], which is no longer configured');
});

it('puts a read-only file back on rollback instead of claiming it did', function (): void {
    config()->set('backup-manager.profiles.test.type', 'full');
    file_put_contents("{$this->filesDir}/key.pem", 'backed-up key');
    chmod("{$this->filesDir}/key.pem", 0400);
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    chmod("{$this->filesDir}/key.pem", 0600);
    file_put_contents("{$this->filesDir}/key.pem", 'current key');

    // Files restore first is not guaranteed; force a failure after files by
    // removing the SQLite connection target the database restore needs.
    $guard = app(RestoreGuard::class);
    $point = new RestorePoint;
    $copy = "{$this->work}/saved-key";
    file_put_contents($copy, 'current key');
    $point->files["{$this->filesDir}/key.pem"] = $copy;
    $point->modes["{$this->filesDir}/key.pem"] = 0600;

    // The restore has written the backed-up, read-only key.
    file_put_contents("{$this->filesDir}/key.pem", 'backed-up key');
    chmod("{$this->filesDir}/key.pem", 0400);

    expect($guard->rollback($point))->toBe([]);

    clearstatcache();
    expect(file_get_contents("{$this->filesDir}/key.pem"))->toBe('current key')
        ->and(fileperms("{$this->filesDir}/key.pem") & 0777)->toBe(0600);
});

it('refuses to write through an existing symlink that leaves the profile', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlinks');
    }

    mkdir("{$this->filesDir}/sub");
    file_put_contents("{$this->filesDir}/sub/a.txt", 'inside');
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    // Since the backup, sub/ became a link to somewhere else entirely.
    $outside = "{$this->work}/outside";
    mkdir($outside);
    unlink("{$this->filesDir}/sub/a.txt");
    rmdir("{$this->filesDir}/sub");
    symlink($outside, "{$this->filesDir}/sub");

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly()))
        ->toThrow(RestoreException::class, 'outside the profile');

    expect(is_file("{$outside}/a.txt"))->toBeFalse();
});

it('does not recreate a link whose target escapes through another link', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlinks');
    }

    $outside = "{$this->work}/outside";
    mkdir($outside);
    file_put_contents("{$outside}/secret.txt", 'not ours');
    symlink("{$outside}/secret.txt", "{$this->filesDir}/door"); // inside, pointing out
    symlink('door', "{$this->filesDir}/hall");                  // lexically inside: ./door
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    unlink("{$this->filesDir}/hall");
    $result = app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(is_link("{$this->filesDir}/hall"))->toBeFalse()
        ->and($result->skippedLinks)->toContain("{$this->filesDir}/hall");
});

it('refuses a backup whose manifest names another profile than the one restoring it', function (): void {
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly(), 'other');
})->throws(RestoreException::class, 'restored for [other]');

it('refuses an unsigned dump for a client that cannot lock down, unless trusted', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('shell scripts');
    }

    config()->set('backup-manager.profiles.test.type', 'database');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    // Point the backup's database at a MySQL connection whose client only
    // understands --system-command: not enough to stop `source` or `tee`.
    $json = json_decode((string) Storage::disk('local')->get($backup->manifestPath), true);
    $json['databases'][0]['connection'] = 'bm_mysql';
    Storage::disk('local')->put($backup->manifestPath, (string) json_encode($json));

    $client = fakeClient('mysql', '  --system-command    Enable or disable the system command.');
    config()->set('database.connections.bm_mysql', ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'x', 'username' => 'x']);
    config()->set('backup-manager.binaries', ['mysql' => $client, 'mariadb' => $client]);
    app()->forgetInstance(BinaryLocator::class);
    app()->forgetInstance(DatabaseManager::class);
    app()->forgetInstance(RestoreManager::class);

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::databasesOnly(verifyChecksums: false)))
        ->toThrow(RestoreException::class, 'not authenticated');

    // Trusted: the gate opens (and the restore then fails on the fake client).
    config()->set('backup-manager.restore.trust_unsigned_dumps', true);

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::databasesOnly(verifyChecksums: false)))
        ->not->toThrow(RestoreException::class, 'not authenticated');
});

it('removes a link whose target leaves the profile only when the kernel resolves it', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('symlinks');
    }

    // `s -> .` is the root itself, so `s/../escape.txt` reads as
    // root/escape.txt lexically — but the kernel follows s first, and `..`
    // then climbs out of the root.
    file_put_contents("{$this->work}/escape.txt", 'not ours');
    symlink('.', "{$this->filesDir}/s");
    symlink('s/../escape.txt', "{$this->filesDir}/x");
    config()->set('backup-manager.profiles.test.type', 'files');
    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    unlink("{$this->filesDir}/x");
    $result = app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(is_link("{$this->filesDir}/x"))->toBeFalse()
        ->and($result->skippedLinks)->toContain("{$this->filesDir}/x");
});

/**
 * Rewrite a stored CBC archive into the untagged pre-1.4 format, as someone
 * with write access to the bucket could, fixing up the unsigned manifest.
 */
function downgradeToLegacyCbc(StoredBackup $backup, string $key): void
{
    $disk = Storage::disk('local');
    $tagged = tempnam(sys_get_temp_dir(), 'bm-tagged-');
    $plain = tempnam(sys_get_temp_dir(), 'bm-plain-');
    file_put_contents($tagged, (string) $disk->get($backup->archivePath));

    (new OpenSslEncryptor(
        EncryptionAlgorithm::Aes256Cbc,
        EncryptionKey::fromConfig($key),
    ))->decrypt($tagged, $plain);

    $iv = random_bytes(16);
    $legacy = 'BME1'.chr(1).$iv.openssl_encrypt((string) file_get_contents($plain), 'aes-256-cbc', base64_decode(substr($key, 7)), OPENSSL_RAW_DATA, $iv);
    $disk->put($backup->archivePath, $legacy);

    $json = json_decode((string) $disk->get($backup->manifestPath), true);
    $json['schema_version'] = '1.2';
    $json['archive']['checksum']['digest'] = hash('sha256', $legacy);
    $disk->put($backup->manifestPath, (string) json_encode($json));
}

it('refuses an unsigned archive rewritten into the legacy CBC format unless allowed', function (): void {
    $key = 'base64:'.base64_encode(random_bytes(32));
    config()->set('backup-manager.encryption.algorithm', 'aes-256-cbc');
    config()->set('backup-manager.encryption.key', $key);
    config()->set('backup-manager.profiles.test.type', 'files');
    app()->forgetInstance(EncryptorManager::class);
    app()->forgetInstance(RestoreManager::class);

    app(BackupManager::class)->run();
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    downgradeToLegacyCbc($backup, $key);
    unlink("{$this->filesDir}/app.php");

    expect(fn () => app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly()))
        ->toThrow(EncryptionException::class, 'cannot prove');

    config()->set('backup-manager.encryption.allow_legacy_cbc', true);
    app(RestoreManager::class)->restore($backup, RestoreOptions::filesOnly());

    expect(file_get_contents("{$this->filesDir}/app.php"))->toBe('return ["app"];');
});
