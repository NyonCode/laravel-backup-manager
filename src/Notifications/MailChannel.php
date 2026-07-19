<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Notifications;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Nyoncode\BackupManager\Contracts\Notification\NotificationChannel;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers notifications by e-mail to the configured recipients.
 *
 * The free core's single notification channel. Delivery is best-effort: a send
 * that throws is logged and swallowed so a failing mail server never breaks a
 * backup or monitor run. With no recipients configured it is a silent no-op.
 */
final class MailChannel implements NotificationChannel
{
    public function __construct(
        private readonly Repository $config,
        private readonly Mailer $mailer,
        private readonly LoggerInterface $logger,
    ) {}

    public function send(string $subject, string $body): void
    {
        $recipients = array_filter((array) $this->config->get('backup-manager.monitoring.notifications.mail.to', []));

        foreach ($recipients as $recipient) {
            try {
                $this->mailer->raw($body, static function ($message) use ($recipient, $subject): void {
                    $message->to($recipient)->subject($subject);
                });
            } catch (Throwable $exception) {
                $this->logger->warning('[backup-manager] Failed to send mail notification', [
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
