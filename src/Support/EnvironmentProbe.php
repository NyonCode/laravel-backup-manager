<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Nyoncode\BackupManager\ValueObjects\EnvironmentInfo;

/**
 * Captures a snapshot of the current runtime for inclusion in a manifest.
 */
final class EnvironmentProbe
{
    public function __construct(
        private readonly Application $app,
    ) {}

    public function capture(string $appName): EnvironmentInfo
    {
        return new EnvironmentInfo(
            appName: $appName,
            appVersion: $this->appVersion(),
            laravelVersion: $this->app->version(),
            phpVersion: PHP_VERSION,
            environment: $this->app->environment(),
            operatingSystem: php_uname('s').' '.php_uname('r'),
        );
    }

    private function appVersion(): ?string
    {
        $version = $this->app->make(Repository::class)->get('app.version');

        return is_string($version) && $version !== '' ? $version : null;
    }
}
