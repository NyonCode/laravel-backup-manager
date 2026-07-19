<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\DTOs\FileSource;
use Nyoncode\BackupManager\Enums\BackupMode;
use Nyoncode\BackupManager\Enums\BackupType;
use Nyoncode\BackupManager\Exceptions\InvalidConfigurationException;

/**
 * Turns a configuration profile into a typed {@see BackupDefinition}.
 *
 * This is the single translation point between raw config arrays and the
 * strongly-typed objects the pipeline consumes.
 */
final class ProfileResolver
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    public function resolve(?string $profile = null): BackupDefinition
    {
        $profile ??= (string) $this->config->get('backup-manager.default', 'default');

        /** @var array<string, mixed>|null $definition */
        $definition = $this->config->get("backup-manager.profiles.{$profile}");

        if (! is_array($definition)) {
            throw InvalidConfigurationException::unknownProfile($profile);
        }

        $type = BackupType::from($definition['type'] ?? 'full');

        return new BackupDefinition(
            profile: $profile,
            name: (string) ($definition['name'] ?? $this->config->get('app.name', 'Laravel')),
            type: $type,
            databases: $type->includesDatabases()
                ? $this->resolveDatabases($definition['databases'] ?? [])
                : [],
            files: $this->resolveFiles($definition['files'] ?? []),
            destinations: array_values((array) ($definition['destinations'] ?? ['local'])),
            path: (string) ($definition['path'] ?? 'backups'),
            retention: $definition['retention'] ?? null,
            mode: BackupMode::from($definition['mode'] ?? 'archive'),
        );
    }

    /**
     * @param  array<string, mixed>  $databases
     * @return list<DatabaseTarget>
     */
    private function resolveDatabases(array $databases): array
    {
        $connections = (array) ($databases['connections'] ?? []);

        if ($connections === []) {
            $connections = [(string) $this->config->get('database.default')];
        }

        $targets = [];

        foreach ($connections as $connection) {
            /** @var array<string, mixed>|null $connectionConfig */
            $connectionConfig = $this->config->get("database.connections.{$connection}");

            if (! is_array($connectionConfig)) {
                throw InvalidConfigurationException::missingKey("database.connections.{$connection}");
            }

            $targets[] = DatabaseTarget::fromConnection(
                connection: $connection,
                config: $connectionConfig,
                excludeTables: array_values((array) ($databases['exclude_tables'] ?? [])),
                schemaOnlyTables: array_values((array) ($databases['schema_only_tables'] ?? [])),
            );
        }

        return $targets;
    }

    /**
     * @param  array<string, mixed>  $files
     */
    private function resolveFiles(array $files): FileSource
    {
        return new FileSource(
            includePaths: array_values((array) ($files['include'] ?? [])),
            excludePatterns: array_values((array) ($files['exclude'] ?? [])),
            followSymlinks: (bool) ($files['follow_symlinks'] ?? false),
            includeHidden: (bool) ($files['include_hidden'] ?? true),
        );
    }
}
