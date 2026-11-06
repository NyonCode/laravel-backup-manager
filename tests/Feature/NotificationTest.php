<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Contracts\Mail\Mailer;
use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Nyoncode\BackupManager\DTOs\FileSource;
use Nyoncode\BackupManager\Enums\BackupType;
use Nyoncode\BackupManager\Events\BackupFailed;
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

it('builds neither the notifier nor its listener while the application boots', function (): void {
    expect(app()->resolved(BackupNotifier::class))->toBeFalse();
});

it('mails on a failed backup through the lazily registered listener', function (): void {
    config()->set('backup-manager.monitoring.notifications', [
        'enabled' => true,
        'on' => ['failure'],
        'mail' => ['to' => ['ops@acme.test']],
    ]);

    $mailer = Mockery::mock(Mailer::class);
    $mailer->shouldReceive('raw')->once();
    app()->instance(Mailer::class, $mailer);
    app()->instance('mailer', $mailer);

    event(new BackupFailed(
        new BackupDefinition('nightly', 'Nightly', BackupType::Full, [], new FileSource([]), [], '/tmp'),
        new RuntimeException('disk full'),
    ));
});

it('reads the config of the container current when it notifies, not of the one that built it', function (): void {
    // What a long-lived worker (Octane) does: one notifier for the worker, a
    // fresh clone of the application — with its own config — per request.
    config()->set('backup-manager.monitoring.notifications', ['enabled' => true, 'on' => ['failure']]);
    $notifier = app(BackupNotifier::class);

    $request = clone app();
    $request->instance('config', $config = clone app('config'));
    $config->set('backup-manager.monitoring.notifications.enabled', false);

    $booted = Container::getInstance();
    Container::setInstance($request);

    try {
        expect($notifier->shouldNotify('failure'))->toBeFalse();
    } finally {
        Container::setInstance($booted);
    }

    expect($notifier->shouldNotify('failure'))->toBeTrue();
});
