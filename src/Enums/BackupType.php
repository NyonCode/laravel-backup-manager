<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * The scope of a backup run.
 */
enum BackupType: string
{
    case Full = 'full';
    case DatabaseOnly = 'database';
    case FilesOnly = 'files';

    public function includesDatabases(): bool
    {
        return $this === self::Full || $this === self::DatabaseOnly;
    }

    public function includesFiles(): bool
    {
        return $this === self::Full || $this === self::FilesOnly;
    }

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Full backup',
            self::DatabaseOnly => 'Database only',
            self::FilesOnly => 'Files only',
        };
    }
}
