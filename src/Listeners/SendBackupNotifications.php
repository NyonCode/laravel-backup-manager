<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Listeners;

use Nyoncode\BackupManager\Events\BackupFailed;
use Nyoncode\BackupManager\Services\BackupNotifier;

/**
 * Notifies on backup failures through the registered channels.
 *
 * The free core notifies on failures only; the Pro package adds monitoring
 * alerts (unhealthy destinations) via its own listener.
 */
final class SendBackupNotifications
{
    public function __construct(
        private readonly BackupNotifier $notifier,
    ) {}

    public function handleFailed(BackupFailed $event): void
    {
        $this->notifier->failure($event->definition->profile, $event->exception->getMessage());
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            BackupFailed::class => 'handleFailed',
        ];
    }
}
