<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Nyoncode\BackupManager\Commands\Concerns\ResolvesBackups;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Managers\RetentionManager;
use Nyoncode\BackupManager\Support\Bytes;
use Nyoncode\BackupManager\Support\ProfileResolver;

/**
 * Applies the retention policy to a profile's destinations.
 */
final class BackupCleanCommand extends Command
{
    use ResolvesBackups;

    protected $signature = 'backup:clean
        {--profile= : The backup profile}
        {--disk= : Only clean this destination disk}
        {--dry-run : Show what would be deleted without deleting}';

    protected $description = 'Delete backups that fall outside the retention policy';

    public function handle(
        ProfileResolver $resolver,
        RetentionManager $retention,
        SnapshotDriver $snapshotRetention,
    ): int {
        $definition = $this->definition($resolver);
        $policy = RetentionPolicy::fromConfig(
            (array) config('backup-manager.retention', []),
            $definition->retention,
        );

        $disk = $this->diskOption();
        $disks = $disk !== null ? [$disk] : $definition->destinations;
        $dryRun = (bool) $this->option('dry-run');
        $totalDeleted = 0;

        foreach ($disks as $disk) {
            $snapshots = $snapshotRetention->applyRetention($disk, $definition->path, $policy, $dryRun);

            $deleted = array_merge(
                $retention->apply($disk, $definition->path, $policy, $dryRun),
                $snapshots->deletedSnapshots,
            );
            $totalDeleted += count($deleted);

            $this->components->twoColumnDetail(
                "<fg=cyan>{$disk}</>",
                sprintf('%s %d backup(s)', $dryRun ? 'would delete' : 'deleted', count($deleted)),
            );

            foreach ($deleted as $backup) {
                $this->components->twoColumnDetail("  {$backup->name}", $backup->createdAt->format('Y-m-d H:i'));
            }

            if ($snapshots->reclaimedBlobs > 0) {
                $this->components->twoColumnDetail(
                    '  <fg=gray>blob pool</>',
                    sprintf(
                        '%s %d blob(s) · %s',
                        $dryRun ? 'would reclaim' : 'reclaimed',
                        $snapshots->reclaimedBlobs,
                        Bytes::human($snapshots->reclaimedBytes),
                    ),
                );
            }
        }

        $this->newLine();
        $this->components->info($dryRun
            ? sprintf('Dry run complete — %d backup(s) would be removed.', $totalDeleted)
            : sprintf('Retention applied — %d backup(s) removed.', $totalDeleted));

        return self::SUCCESS;
    }
}
