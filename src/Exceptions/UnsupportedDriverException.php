<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

/**
 * Thrown when an unknown or unsupported driver is requested.
 */
final class UnsupportedDriverException extends BackupManagerException
{
    public static function forDatabaseDriver(string $driver): self
    {
        return new self(
            "Unsupported database driver [{$driver}]. Supported drivers are: mysql, mariadb, pgsql, sqlite."
        );
    }

    public static function forStorageDriver(string $driver): self
    {
        return new self("Unsupported storage driver [{$driver}].");
    }
}
