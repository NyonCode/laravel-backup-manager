<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\EnvironmentProbe;
use Nyoncode\BackupManager\ValueObjects\EnvironmentInfo;

it('captures the runtime environment for the manifest', function (): void {
    config()->set('app.version', '2.4.1');

    $info = app(EnvironmentProbe::class)->capture('Acme Web');

    expect($info)->toBeInstanceOf(EnvironmentInfo::class)
        ->and($info->appName)->toBe('Acme Web')
        ->and($info->appVersion)->toBe('2.4.1')
        ->and($info->phpVersion)->toBe(PHP_VERSION)
        ->and($info->laravelVersion)->not->toBe('')
        ->and($info->environment)->toBe(app()->environment())
        ->and($info->operatingSystem)->toContain(php_uname('s'));
});

it('records a null app version when none is configured', function (): void {
    config()->set('app.version', '');

    expect(app(EnvironmentProbe::class)->capture('Acme')->appVersion)->toBeNull();
});

it('round-trips through the manifest array form', function (): void {
    config()->set('app.version', '9.9.9');

    $info = app(EnvironmentProbe::class)->capture('Acme');
    $restored = EnvironmentInfo::fromArray($info->toArray());

    expect($restored->toArray())->toBe($info->toArray());
});
