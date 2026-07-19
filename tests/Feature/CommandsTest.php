<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-cmd-'.uniqid();
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
    DB::connection('bm_sqlite')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
});

it('registers all backup commands', function (): void {
    $commands = array_keys(app('Illuminate\Contracts\Console\Kernel')->all());

    expect($commands)->toContain('backup:run')
        ->toContain('backup:list')
        ->toContain('backup:restore')
        ->toContain('backup:clean')
        ->toContain('backup:verify');
});

it('runs, lists and verifies a backup through the CLI', function (): void {
    $this->artisan('backup:run')->assertExitCode(0);

    expect(Storage::disk('local')->files('backups'))->not->toBeEmpty();

    $this->artisan('backup:list')->assertExitCode(0);
    $this->artisan('backup:verify')->assertExitCode(0);
    $this->artisan('backup:clean --dry-run')->assertExitCode(0);
});
