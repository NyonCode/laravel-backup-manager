<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Services;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Notification\NotificationChannel;

/**
 * Fans an operational notification out to every registered channel.
 *
 * The free core wires a single mail channel and notifies on failures; the Pro
 * package adds channels (Slack) and events (unhealthy destinations) by tagging
 * more {@see NotificationChannel}s and dispatching through {@see notify()}.
 * Whether an event notifies at all is gated by config; individual channels
 * deliver best-effort and never let a send failure escape.
 */
final class BackupNotifier
{
    /**
     * @param  iterable<NotificationChannel>  $channels
     */
    public function __construct(
        private readonly Repository $config,
        private readonly iterable $channels,
    ) {}

    public function failure(string $profile, string $error): void
    {
        if (! $this->shouldNotify('failure')) {
            return;
        }

        $this->notify(
            "Backup failed: {$profile}",
            "The backup for profile [{$profile}] failed:\n\n{$error}",
        );
    }

    /**
     * Deliver a message through every registered channel.
     */
    public function notify(string $subject, string $body): void
    {
        foreach ($this->channels as $channel) {
            $channel->send($subject, $body);
        }
    }

    /**
     * Whether notifications are enabled and this event is opted in.
     */
    public function shouldNotify(string $event): bool
    {
        if (! $this->config->get('backup-manager.monitoring.notifications.enabled', true)) {
            return false;
        }

        return in_array($event, (array) $this->config->get('backup-manager.monitoring.notifications.on', []), true);
    }
}
