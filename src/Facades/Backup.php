<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Facades;

use Illuminate\Support\Facades\Facade;
use Nyoncode\BackupManager\Managers\BackupManager;

/**
 * @method static \Nyoncode\BackupManager\ValueObjects\BackupResult run(?string $profile = null)
 *
 * @see BackupManager
 */
final class Backup extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BackupManager::class;
    }
}
