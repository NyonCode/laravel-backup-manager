<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Snapshot\NullSnapshotDriver;

/**
 * Without the Pro package the core binds the null snapshot driver: it can still
 * read and list snapshots, but any attempt to create, restore or verify one
 * fails with a clear "requires Pro" message rather than a confusing error.
 */
beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-nopro-'.uniqid();
    $this->filesDir = "{$this->work}/site";

    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/index.php", '<?php echo "v1";');

    config()->set('backup-manager.default', 'inc');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.profiles', [
        'inc' => [
            'name' => 'Acme',
            'type' => 'files',
            'mode' => 'incremental',
            'databases' => ['connections' => []],
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
});

it('binds the null snapshot driver when Pro is absent', function (): void {
    $driver = app(SnapshotDriver::class);

    expect($driver)->toBeInstanceOf(NullSnapshotDriver::class)
        ->and($driver->supported())->toBeFalse();
});

it('refuses to create an incremental backup without Pro', function (): void {
    app(BackupManager::class)->run();
})->throws(Exception::class, 'require the nyoncode/backup-manager-pro package');

it('treats snapshot retention as a safe no-op without Pro', function (): void {
    $result = app(SnapshotDriver::class)->applyRetention(
        'local',
        'backups',
        new RetentionPolicy(strategy: 'simple', keepLatest: 3),
    );

    expect($result->deletedSnapshots)->toBe([])
        ->and($result->reclaimedBlobs)->toBe(0)
        ->and($result->reclaimedBytes)->toBe(0);
});
