<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

use Nyoncode\BackupManager\Exceptions\UnsupportedDriverException;

/**
 * Supported database drivers.
 *
 * Values match Laravel's database connection driver identifiers so an
 * instance can be resolved directly from a connection's configuration.
 */
enum DatabaseDriver: string
{
    case MySql = 'mysql';
    case MariaDb = 'mariadb';
    case PostgreSql = 'pgsql';
    case Sqlite = 'sqlite';

    /**
     * Resolve a driver from a Laravel connection driver string.
     *
     * @throws UnsupportedDriverException When the driver is not supported.
     */
    public static function fromConnectionDriver(string $driver): self
    {
        return self::tryFrom($driver)
            ?? throw UnsupportedDriverException::forDatabaseDriver($driver);
    }

    /**
     * Human readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::MySql => 'MySQL',
            self::MariaDb => 'MariaDB',
            self::PostgreSql => 'PostgreSQL',
            self::Sqlite => 'SQLite',
        };
    }

    /**
     * Whether the driver stores its data in a single file rather than a server.
     */
    public function isFileBased(): bool
    {
        return $this === self::Sqlite;
    }

    /**
     * The conventional dump file extension for this driver.
     */
    public function dumpExtension(): string
    {
        return $this->isFileBased() ? 'sqlite' : 'sql';
    }
}
