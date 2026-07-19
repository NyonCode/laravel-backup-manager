<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\RestoreManager;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\ValueObjects\Manifest;

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
