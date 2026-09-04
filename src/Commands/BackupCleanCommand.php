<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Nyoncode\BackupManager\Commands\Concerns\QueuesWork;
use Nyoncode\BackupManager\Commands\Concerns\ResolvesBackups;
use Nyoncode\BackupManager\Jobs\CleanBackupsJob;
use Nyoncode\BackupManager\Managers\CleanupManager;
use Nyoncode\BackupManager\Support\Bytes;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\ValueObjects\CleanupReport;

/**
 * Applies the retention policy to a profile's destinations.
 */
final class BackupCleanCommand extends Command
{
    use QueuesWork;
    use ResolvesBackups;

    protected $signature = 'backup:clean
        {--profile= : The backup profile}
        {--disk= : Only clean this destination disk}
        {--dry-run : Show what would be deleted without deleting}
        {--sync : Run immediately even when queued backups are enabled}';

    protected $description = 'Delete backups that fall outside the retention policy';

    public function handle(ProfileResolver $resolver, CleanupManager $cleanup, Dispatcher $bus): int
    {
        $definition = $this->definition($resolver);
        $disk = $this->diskOption();
        $dryRun = (bool) $this->option('dry-run');

        // A dry run always stays here: its entire product is the preview below,
        // which a worker has nowhere to print.
        if (! $dryRun && $this->shouldQueue()) {
            $bus->dispatch(new CleanBackupsJob($this->profileOption(), $disk));
            $this->components->info(sprintf('Retention for profile [%s] dispatched to the queue.', $definition->profile));

            return self::SUCCESS;
        }

        $totalDeleted = 0;

        foreach ($cleanup->clean($definition, $disk, $dryRun) as $report) {
            $totalDeleted += $report->deletedCount();
            $this->report($report, $dryRun);
        }

        $this->newLine();
        $this->components->info($dryRun
            ? sprintf('Dry run complete — %d backup(s) would be removed.', $totalDeleted)
            : sprintf('Retention applied — %d backup(s) removed.', $totalDeleted));

        return self::SUCCESS;
    }

    private function report(CleanupReport $report, bool $dryRun): void
    {
        $this->components->twoColumnDetail(
            "<fg=cyan>{$report->disk}</>",
            sprintf('%s %d backup(s)', $dryRun ? 'would delete' : 'deleted', $report->deletedCount()),
        );

        foreach ($report->deleted as $backup) {
            $this->components->twoColumnDetail("  {$backup->name}", $backup->createdAt->format('Y-m-d H:i'));
        }

        if ($report->reclaimedBlobs > 0) {
            $this->components->twoColumnDetail(
                '  <fg=gray>blob pool</>',
                sprintf(
                    '%s %d blob(s) · %s',
                    $dryRun ? 'would reclaim' : 'reclaimed',
                    $report->reclaimedBlobs,
                    Bytes::human($report->reclaimedBytes),
                ),
            );
        }
    }
}
