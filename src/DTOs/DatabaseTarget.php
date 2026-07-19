<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\DTOs;

use Nyoncode\BackupManager\Enums\DatabaseDriver;

/**
 * An immutable description of a single database to be dumped or restored.
 *
 * Built from a Laravel connection configuration array, normalising the
 * fields the external dump/restore tools require.
 */
final readonly class DatabaseTarget
{
    /**
     * @param  list<string>  $excludeTables
     * @param  list<string>  $schemaOnlyTables
     */
    public function __construct(
        public string $connection,
        public DatabaseDriver $driver,
        public string $database,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $username = null,
        public ?string $password = null,
        public array $excludeTables = [],
        public array $schemaOnlyTables = [],
    ) {}

    /**
     * Build a target from a Laravel connection config array.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string>  $excludeTables
     * @param  list<string>  $schemaOnlyTables
     */
    public static function fromConnection(
        string $connection,
        array $config,
        array $excludeTables = [],
        array $schemaOnlyTables = [],
    ): self {
        $driver = DatabaseDriver::fromConnectionDriver((string) ($config['driver'] ?? ''));

        return new self(
            connection: $connection,
            driver: $driver,
            database: (string) ($config['database'] ?? ''),
            host: isset($config['host']) ? (string) $config['host'] : null,
            port: isset($config['port']) ? (int) $config['port'] : null,
            username: isset($config['username']) ? (string) $config['username'] : null,
            password: isset($config['password']) ? (string) $config['password'] : null,
            excludeTables: $excludeTables,
            schemaOnlyTables: $schemaOnlyTables,
        );
    }

    /**
     * For file-based drivers (SQLite) the "database" is a filesystem path.
     */
    public function isFileBased(): bool
    {
        return $this->driver->isFileBased();
    }
}
