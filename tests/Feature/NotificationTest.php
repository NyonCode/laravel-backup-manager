<?php

declare(strict_types=1);

use Illuminate\Contracts\Mail\Mailer;
use Nyoncode\BackupManager\Notifications\MailChannel;
use Nyoncode\BackupManager\Services\BackupNotifier;

/**
 * The free core notifies on failures through its single mail channel. Slack and
 * unhealthy-destination alerts are covered by the Pro suite.
 */
function notifier(Mailer $mailer): BackupNotifier
{
    return new BackupNotifier(app('config'), [new MailChannel(app('config'), $mailer, app('log'))]);
}

it('mails each recipient on failure when configured', function (): void {
    config()->set('backup-manager.monitoring.notifications', [
        'enabled' => true,
        'on' => ['failure'],
        'mail' => ['to' => ['ops@acme.test', 'dev@acme.test']],
    ]);

    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('raw')->twice(); // one per recipient

    notifier($mailer)->failure('nightly', 'disk full');
});

it('does not notify when the event is not in the "on" list', function (): void {
    config()->set('backup-manager.monitoring.notifications', [
        'enabled' => true,
        'on' => ['unhealthy'], // failure not listed
        'mail' => ['to' => ['x@y.test']],
    ]);

    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldNotReceive('raw');

    notifier($mailer)->failure('nightly', 'boom');
});

it('does not notify when notifications are disabled', function (): void {
    config()->set('backup-manager.monitoring.notifications.enabled', false);

    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldNotReceive('raw');

    notifier($mailer)->failure('nightly', 'boom');
});
