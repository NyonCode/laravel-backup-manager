<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

use Nyoncode\BackupManager\Enums\DatabaseDriver;

/**
 * Describes a single database dump contained in a backup.
 */
final readonly class DatabaseArtifact
{
    public function __construct(
        public string $connection,
        public DatabaseDriver $driver,
        public string $databaseName,
        public string $archivedPath,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            connection: $data['connection'],
            driver: DatabaseDriver::from($data['driver']),
            databaseName: $data['database_name'],
            archivedPath: $data['archived_path'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'connection' => $this->connection,
            'driver' => $this->driver->value,
            'database_name' => $this->databaseName,
            'archived_path' => $this->archivedPath,
        ];
    }
}
