<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nyoncode\BackupManager\Jobs\Concerns\RoutesToBackupQueue;
use Nyoncode\BackupManager\Managers\BackupManager;

/**
 * Runs a backup on the queue.
 *
 * Dispatched by the CLI (and available to your own code) when queued backups
 * are enabled, so a long-running backup does not block the request or command.
 * The connection, queue and timeout come from the package configuration.
 */
final class RunBackupJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use RoutesToBackupQueue;
    use SerializesModels;

    public function __construct(
        public readonly ?string $profile = null,
    ) {
        $this->routeToBackupQueue();
    }

    public function handle(BackupManager $manager): void
    {
        $manager->run($this->profile);
    }
}
