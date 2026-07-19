<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Tests;

use Nyoncode\BackupManager\BackupManagerServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            BackupManagerServiceProvider::class,
        ];
    }
}
