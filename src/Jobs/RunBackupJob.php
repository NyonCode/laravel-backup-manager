<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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
    use SerializesModels;

    /**
     * Seconds the job may run before timing out.
     */
    public int $timeout;

    public function __construct(
        public readonly ?string $profile = null,
    ) {
        $config = app(Repository::class);

        $this->onConnection($config->get('backup-manager.queue.connection'));
        $this->onQueue((string) $config->get('backup-manager.queue.queue', 'backups'));
        $this->timeout = (int) $config->get('backup-manager.queue.timeout', 3600);
    }

    public function handle(BackupManager $manager): void
    {
        $manager->run($this->profile);
    }
}
