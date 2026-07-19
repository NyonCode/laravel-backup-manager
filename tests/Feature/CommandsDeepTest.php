<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-cmd-deep-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    $this->dbFile = "{$this->work}/database.sqlite";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/a.txt", 'a');
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
    config()->set('backup-manager.encryption.algorithm', 'none');
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
    DB::connection('bm_sqlite')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)');
});

it('lists gracefully when no backups exist yet', function (): void {
    $this->artisan('backup:list')->assertExitCode(0);
});

it('fails verification for a backup that does not exist', function (): void {
    $this->artisan('backup:run')->assertExitCode(0);

    $this->artisan('backup:verify', ['backup' => 'not-a-real-backup.tar.gz'])
        ->assertFailed();
});

it('verifies a specific backup by name', function (): void {
    $this->artisan('backup:run')->assertExitCode(0);
    $name = app(BackupRepository::class)->list('local', 'backups')[0]->name;

    $this->artisan('backup:verify', ['backup' => $name])->assertExitCode(0);
});

it('applies retention for real but keeps a backup the policy protects', function (): void {
    $this->artisan('backup:run')->assertExitCode(0);
    expect(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(1);

    // Default policy protects the sole backup — a real clean must never delete it.
    $this->artisan('backup:clean')->assertExitCode(0);

    expect(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(1);
});

it('restores a database through the CLI with --force --database', function (): void {
    DB::connection('bm_sqlite')->table('t')->insert(['id' => 1, 'name' => 'before']);

    $this->artisan('backup:run')->assertExitCode(0);

    // Simulated data loss.
    DB::connection('bm_sqlite')->table('t')->delete();
    expect(DB::connection('bm_sqlite')->table('t')->count())->toBe(0);

    $this->artisan('backup:restore', ['--force' => true, '--database' => true])
        ->assertExitCode(0);
    DB::purge('bm_sqlite');

    expect(DB::connection('bm_sqlite')->table('t')->where('name', 'before')->exists())->toBeTrue();
});
