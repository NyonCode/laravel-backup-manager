<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Jobs\RunBackupJob;
use Nyoncode\BackupManager\Managers\BackupManager;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-queue-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    $this->dbFile = "{$this->work}/database.sqlite";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/a.txt", 'a');
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
    DB::connection('bm_sqlite')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
});

it('dispatches a queued job instead of running inline when queue is enabled', function (): void {
    config()->set('backup-manager.queue.enabled', true);
    config()->set('backup-manager.queue.queue', 'backups');
    Queue::fake();

    $this->artisan('backup:run')->assertExitCode(0);

    Queue::assertPushed(RunBackupJob::class, fn (RunBackupJob $job) => $job->queue === 'backups');
    // Nothing was written synchronously.
    expect(Storage::disk('local')->files('backups'))->toBeEmpty();
});

it('runs synchronously with --sync even when queue is enabled', function (): void {
    config()->set('backup-manager.queue.enabled', true);
    Queue::fake();

    $this->artisan('backup:run --sync')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(Storage::disk('local')->files('backups'))->not->toBeEmpty();
});

it('runs inline by default when queue is disabled', function (): void {
    config()->set('backup-manager.queue.enabled', false);
    Queue::fake();

    $this->artisan('backup:run')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(Storage::disk('local')->files('backups'))->not->toBeEmpty();
});

it('actually produces a backup when the job is handled', function (): void {
    config()->set('backup-manager.queue.enabled', true);
    config()->set('backup-manager.queue.timeout', 120);

    $job = new RunBackupJob('test');
    expect($job->timeout)->toBe(120)
        ->and($job->queue)->toBe('backups');

    $job->handle(app(BackupManager::class));

    expect(Storage::disk('local')->files('backups'))->not->toBeEmpty();
});
