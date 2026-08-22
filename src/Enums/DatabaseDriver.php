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
     * Command line tools that can dump this driver, best first.
     *
     * MariaDB renamed its clients in 11.0 — `mysqldump` became `mariadb-dump`,
     * and the old names survive only as deprecated symlinks that several
     * distributions and container images no longer ship. Both names are
     * therefore tried, ordered by which server is actually being backed up: a
     * host with both clients installed should not dump a MariaDB database with
     * MySQL's tool, whose output MariaDB cannot always read back.
     *
     * Empty for file-based drivers, which are copied rather than dumped.
     *
     * @return list<string>
     */
    public function dumpBinaries(): array
    {
        return match ($this) {
            self::MySql => ['mysqldump', 'mariadb-dump'],
            self::MariaDb => ['mariadb-dump', 'mysqldump'],
            self::PostgreSql => ['pg_dump'],
            self::Sqlite => [],
        };
    }

    /**
     * Command line clients that can restore this driver, best first.
     *
     * @return list<string>
     */
    public function clientBinaries(): array
    {
        return match ($this) {
            self::MySql => ['mysql', 'mariadb'],
            self::MariaDb => ['mariadb', 'mysql'],
            self::PostgreSql => ['psql'],
            self::Sqlite => [],
        };
    }

    /**
     * The conventional dump file extension for this driver.
     */
    public function dumpExtension(): string
    {
        return $this->isFileBased() ? 'sqlite' : 'sql';
    }
}
