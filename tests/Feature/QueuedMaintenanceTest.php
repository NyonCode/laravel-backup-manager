<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Jobs\CleanBackupsJob;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\CleanupManager;
use Nyoncode\BackupManager\Support\ProfileResolver;

/**
 * `BACKUP_QUEUE=true` reads as "backup work goes to the queue". It has to hold
 * for retention as well as for the backup itself: scheduled without one, a
 * cleanup that reference-counts an entire blob pool runs inside the scheduler's
 * process on the web machine — no worker memory limit, no `failed_jobs` row, and
 * invisible to the dashboard that is supposed to be watching it.
 */
beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-maint-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/a.txt", 'a');

    config()->set('backup-manager.default', 'test');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'none');
    config()->set('backup-manager.retention.strategy', 'simple');
    config()->set('backup-manager.retention.simple.keep_latest', 1);
    config()->set('backup-manager.profiles', [
        'test' => [
            'name' => 'Acme',
            'type' => 'files',
            'databases' => ['connections' => []],
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
});

function seedArchives(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        app(BackupManager::class)->run('test');
    }
}

function storedArchives(): array
{
    return array_values(array_filter(
        Storage::disk('local')->files('backups'),
        static fn (string $file): bool => ! str_ends_with($file, '.manifest.json'),
    ));
}

it('dispatches retention to the queue instead of running it inline', function (): void {
    seedArchives(2);
    config()->set('backup-manager.queue.enabled', true);
    Queue::fake();

    $this->artisan('backup:clean')->assertExitCode(0);

    Queue::assertPushed(CleanBackupsJob::class, fn (CleanBackupsJob $job): bool => $job->profile === null);
    // Nothing was pruned in this process.
    expect(storedArchives())->toHaveCount(2);
});

it('runs retention inline with --sync even when the queue is enabled', function (): void {
    seedArchives(2);
    config()->set('backup-manager.queue.enabled', true);
    Queue::fake();

    $this->artisan('backup:clean --sync')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(storedArchives())->toHaveCount(1);
});

it('runs retention inline by default when the queue is disabled', function (): void {
    seedArchives(2);
    config()->set('backup-manager.queue.enabled', false);
    Queue::fake();

    $this->artisan('backup:clean')->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(storedArchives())->toHaveCount(1);
});

/**
 * A dry run's entire product is the preview it prints, which a worker has
 * nowhere to show — so it stays in the calling process whatever the setting.
 */
it('never queues a dry run', function (): void {
    seedArchives(2);
    config()->set('backup-manager.queue.enabled', true);
    Queue::fake();

    $this->artisan('backup:clean --dry-run')
        ->expectsOutputToContain('would be removed')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
    expect(storedArchives())->toHaveCount(2);
});

it('carries the profile and disk the command was given', function (): void {
    config()->set('backup-manager.queue.enabled', true);
    Queue::fake();

    $this->artisan('backup:clean --profile=test --disk=local')->assertExitCode(0);

    Queue::assertPushed(
        CleanBackupsJob::class,
        fn (CleanBackupsJob $job): bool => $job->profile === 'test' && $job->disk === 'local',
    );
});

it('routes the retention job onto the backup queue', function (): void {
    config()->set('backup-manager.queue.connection', 'backup-redis');
    config()->set('backup-manager.queue.queue', 'maintenance');
    config()->set('backup-manager.queue.timeout', 7200);

    $job = new CleanBackupsJob('test');

    expect($job->connection)->toBe('backup-redis')
        ->and($job->queue)->toBe('maintenance')
        ->and($job->timeout)->toBe(7200);
});

it('prunes exactly as the command does when the job is handled', function (): void {
    seedArchives(3);

    (new CleanBackupsJob('test'))->handle(app(ProfileResolver::class), app(CleanupManager::class));

    expect(storedArchives())->toHaveCount(1);
});
