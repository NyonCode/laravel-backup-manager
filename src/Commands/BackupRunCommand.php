<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Bus\Dispatcher;
use Nyoncode\BackupManager\Commands\Concerns\QueuesWork;
use Nyoncode\BackupManager\Jobs\RunBackupJob;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Support\Bytes;
use Throwable;

/**
 * Creates a new backup for a profile.
 */
final class BackupRunCommand extends Command
{
    use QueuesWork;

    protected $signature = 'backup:run
        {--profile= : The backup profile to run (defaults to the configured profile)}
        {--sync : Run immediately even when queued backups are enabled}';

    protected $description = 'Create a new backup';

    public function handle(BackupManager $manager, Dispatcher $bus): int
    {
        $profileOption = $this->option('profile');
        $profile = is_string($profileOption) && $profileOption !== '' ? $profileOption : null;
        $profileLabel = $profile ?? (string) config('backup-manager.default');

        if ($this->shouldQueue()) {
            $bus->dispatch(new RunBackupJob($profile));
            $this->components->info(sprintf('Backup for profile [%s] dispatched to the queue.', $profileLabel));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Running backup for profile [%s]…', $profileLabel));

        try {
            $result = $manager->run($profile);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $manifest = $result->manifest;
        $label = $result->isIncremental() ? 'Snapshot' : 'Archive';

        $this->newLine();
        $this->components->twoColumnDetail("<fg=green>{$label}</>", $result->archiveFileName);
        $this->components->twoColumnDetail('Mode', $manifest->mode->label());
        $this->components->twoColumnDetail('Size', Bytes::human($result->archiveSize));
        $this->components->twoColumnDetail('Databases', (string) count($manifest->databases));
        $this->components->twoColumnDetail('Files', (string) $manifest->fileCount());

        if ($result->snapshotDiff !== null) {
            $diff = $result->snapshotDiff;
            $this->components->twoColumnDetail(
                'Deduplicated',
                sprintf(
                    '%d new, %d reused (%s new content)',
                    count($diff->newFiles()),
                    count($diff->unchanged),
                    Bytes::human($diff->newByteSize()),
                ),
            );
        } else {
            $this->components->twoColumnDetail('Compression', $manifest->compression->value);
            $this->components->twoColumnDetail('Encryption', $manifest->encryption->value);
        }

        $this->components->twoColumnDetail('Destinations', implode(', ', $result->destinations));

        $this->newLine();
        $this->components->info('Backup completed successfully.');

        return self::SUCCESS;
    }
}
