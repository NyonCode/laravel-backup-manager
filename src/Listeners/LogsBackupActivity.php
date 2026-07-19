<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Listeners;

use Nyoncode\BackupManager\Events\BackupCompleted;
use Nyoncode\BackupManager\Events\BackupFailed;
use Nyoncode\BackupManager\Events\BackupStarted;
use Psr\Log\LoggerInterface;

/**
 * Writes a structured log entry for every backup lifecycle event.
 *
 * Provides the operation history/audit trail out of the box; richer
 * persistence can be added by subscribing additional listeners.
 */
final class LogsBackupActivity
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handleStarted(BackupStarted $event): void
    {
        $this->logger->info('[backup-manager] Backup started', [
            'profile' => $event->definition->profile,
            'type' => $event->definition->type->value,
        ]);
    }

    public function handleCompleted(BackupCompleted $event): void
    {
        $this->logger->info('[backup-manager] Backup completed', [
            'profile' => $event->result->profile,
            'archive' => $event->result->archiveFileName,
            'size' => $event->result->archiveSize,
            'destinations' => $event->result->destinations,
        ]);
    }

    public function handleFailed(BackupFailed $event): void
    {
        $this->logger->error('[backup-manager] Backup failed', [
            'profile' => $event->definition->profile,
            'error' => $event->exception->getMessage(),
        ]);
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            BackupStarted::class => 'handleStarted',
            BackupCompleted::class => 'handleCompleted',
            BackupFailed::class => 'handleFailed',
        ];
    }
}
