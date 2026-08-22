<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Nyoncode\BackupManager\ValueObjects\MigrationState;
use Throwable;

/**
 * Reads the applied migrations for inclusion in a manifest.
 *
 * Fails soft in every direction: an application with no database, an
 * unreachable one, or one that has never migrated all yield null rather than an
 * error. Recording provenance is worth having, and never worth failing a backup
 * over — a backup that did not happen is a worse outcome than one whose
 * manifest cannot say which migrations were in place.
 */
final class MigrationProbe
{
    public function __construct(
        private readonly DatabaseManager $connections,
        private readonly Repository $config,
    ) {}

    /**
     * The migrations applied on the connection Laravel migrates, or null when
     * that cannot be established.
     */
    public function capture(): ?MigrationState
    {
        try {
            $connection = $this->connections->connection();
            $table = $this->table();

            if (! $connection->getSchemaBuilder()->hasTable($table)) {
                return null;
            }

            /** @var list<string> $ran */
            $ran = $connection->table($table)
                ->orderBy('id')
                ->pluck('migration')
                ->map(static fn (mixed $name): string => (string) $name)
                ->all();

            return new MigrationState($ran);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The migrations table name, read the way Laravel's own migrator reads it:
     * `database.migrations` is a table name, or a config array holding one.
     */
    private function table(): string
    {
        $migrations = $this->config->get('database.migrations', 'migrations');

        $table = is_array($migrations)
            ? ($migrations['table'] ?? null)
            : $migrations;

        return is_string($table) && $table !== '' ? $table : 'migrations';
    }
}
