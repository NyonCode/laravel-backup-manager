<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Nyoncode\BackupManager\Contracts\Database\DatabaseDumper;
use Nyoncode\BackupManager\Contracts\Database\DatabaseRestorer;
use Nyoncode\BackupManager\Database\Dumpers\MySqlDumper;
use Nyoncode\BackupManager\Database\Dumpers\PostgreSqlDumper;
use Nyoncode\BackupManager\Database\Dumpers\SqliteDumper;
use Nyoncode\BackupManager\Database\Restorers\MySqlRestorer;
use Nyoncode\BackupManager\Database\Restorers\PostgreSqlRestorer;
use Nyoncode\BackupManager\Database\Restorers\SqliteRestorer;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Support\BinaryLocator;

/**
 * Resolves the correct dumper/restorer for a given database driver.
 *
 * Acts as the single composition point for database engines: adding support
 * for a new engine means registering it here, nothing else changes.
 */
final class DatabaseManager
{
    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly ?int $timeout = null,
    ) {}

    public function dumper(DatabaseDriver $driver): DatabaseDumper
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => new MySqlDumper($this->binaries, $this->timeout),
            DatabaseDriver::PostgreSql => new PostgreSqlDumper($this->binaries, $this->timeout),
            DatabaseDriver::Sqlite => new SqliteDumper,
        };
    }

    public function restorer(DatabaseDriver $driver): DatabaseRestorer
    {
        return match ($driver) {
            DatabaseDriver::MySql, DatabaseDriver::MariaDb => new MySqlRestorer($this->binaries, $this->timeout),
            DatabaseDriver::PostgreSql => new PostgreSqlRestorer($this->binaries, $this->timeout),
            DatabaseDriver::Sqlite => new SqliteRestorer,
        };
    }
}
