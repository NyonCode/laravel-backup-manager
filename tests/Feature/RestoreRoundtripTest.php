<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\RestoreManager;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-rt-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    $this->dbFile = "{$this->work}/database.sqlite";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/keepme.txt", 'original contents');
    touch($this->dbFile);

    config()->set('database.connections.bm_sqlite', [
        'driver' => 'sqlite',
        'database' => $this->dbFile,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    config()->set('backup-manager.default', 'test');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'aes-256-gcm');
    config()->set('backup-manager.encryption.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('backup-manager.profiles', [
        'test' => [
            'name' => 'Acme',
            'type' => 'full',
            'databases' => ['connections' => ['bm_sqlite']],
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
});

it('restores an encrypted backup: database rows and files return after data loss', function (): void {
    DB::connection('bm_sqlite')->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT)');
    DB::connection('bm_sqlite')->statement("INSERT INTO posts (title) VALUES ('Hello'), ('World')");

    app(BackupManager::class)->run();

    // Simulate data loss.
    DB::connection('bm_sqlite')->statement('DROP TABLE posts');
    file_put_contents("{$this->filesDir}/keepme.txt", 'CORRUPTED');

    $repository = app(BackupRepository::class);
    $backup = $repository->list('local', 'backups')[0];

    $result = app(RestoreManager::class)->restore($backup, RestoreOptions::full());

    DB::purge('bm_sqlite');

    expect($result->restoredDatabases)->toBe(['bm_sqlite'])
        ->and(DB::connection('bm_sqlite')->table('posts')->count())->toBe(2)
        ->and(file_get_contents("{$this->filesDir}/keepme.txt"))->toBe('original contents');
});

it('restores only the database when asked', function (): void {
    DB::connection('bm_sqlite')->statement('CREATE TABLE posts (id INTEGER PRIMARY KEY)');
    app(BackupManager::class)->run();

    file_put_contents("{$this->filesDir}/keepme.txt", 'CORRUPTED');
    DB::connection('bm_sqlite')->statement('DROP TABLE posts');

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    $result = app(RestoreManager::class)->restore($backup, RestoreOptions::databasesOnly());

    DB::purge('bm_sqlite');

    expect($result->restoredFileCount)->toBe(0)
        ->and(file_get_contents("{$this->filesDir}/keepme.txt"))->toBe('CORRUPTED')
        ->and(DB::connection('bm_sqlite')->getSchemaBuilder()->hasTable('posts'))->toBeTrue();
});

it('rejects a tampered archive during verified restore', function (): void {
    DB::connection('bm_sqlite')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
    app(BackupManager::class)->run();

    $disk = Storage::disk('local');
    $archive = collect($disk->files('backups'))->first(fn ($f) => ! str_ends_with($f, '.manifest.json'));
    $disk->put($archive, $disk->get($archive).'tamper-bytes');

    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    app(RestoreManager::class)->restore($backup, RestoreOptions::full());
})->throws(IntegrityException::class);
