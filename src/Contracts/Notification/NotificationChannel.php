<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Notification;

use Nyoncode\BackupManager\Services\BackupNotifier;

/**
 * Capability: deliver an operational notification through one transport.
 *
 * Channels are collected by {@see BackupNotifier}
 * and each one delivers best-effort — a channel that fails to send logs the
 * failure but never lets it break a backup or monitor run. The free core ships
 * a mail channel; the Pro package adds Slack (and more), so richer alerting is
 * a matter of registering another channel, not changing the notifier.
 */
interface NotificationChannel
{
    public function send(string $subject, string $body): void;
}
