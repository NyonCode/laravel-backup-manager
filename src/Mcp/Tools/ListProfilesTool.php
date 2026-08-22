<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Throwable;

/**
 * Every profile as the pipeline actually resolves it — including the defaults a
 * profile inherits, which is where "but I configured that" usually hides.
 */
final class ListProfilesTool extends Tool
{
    public function __construct(
        private readonly Repository $config,
        private readonly ProfileResolver $resolver,
    ) {}

    public function name(): string
    {
        return 'backup_list_profiles';
    }

    public function title(): string
    {
        return 'List backup profiles';
    }

    public function description(): string
    {
        return 'Resolves every configured profile the way the backup pipeline does: what it backs up, '
            .'which database connections and directories it covers, where it is stored, and the retention '
            .'policy that applies to it. Read-only.';
    }

    public function handle(array $arguments): ToolResult
    {
        $profiles = [];
        /** @var array<string, mixed> $configured */
        $configured = (array) $this->config->get('backup-manager.profiles', []);
        /** @var array<string, mixed> $retention */
        $retention = (array) $this->config->get('backup-manager.retention', []);

        foreach (array_keys($configured) as $name) {
            $name = (string) $name;

            try {
                $definition = $this->resolver->resolve($name);
            } catch (Throwable $exception) {
                $profiles[$name] = ['error' => $exception->getMessage()];

                continue;
            }

            $policy = RetentionPolicy::fromConfig($retention, $definition->retention);

            $profiles[$name] = [
                'name' => $definition->name,
                'type' => $definition->type->value,
                'mode' => $definition->mode->value,
                'destinations' => $definition->destinations,
                'path' => $definition->path,
                'databases' => array_map(static fn ($target): array => [
                    'connection' => $target->connection,
                    'driver' => $target->driver->value,
                    'database' => $target->database,
                    'exclude_tables' => $target->excludeTables,
                    'schema_only_tables' => $target->schemaOnlyTables,
                ], $definition->databases),
                'files' => [
                    'include' => $definition->files->includePaths,
                    'exclude' => $definition->files->excludePatterns,
                    'follow_symlinks' => $definition->files->followSymlinks,
                    'include_hidden' => $definition->files->includeHidden,
                ],
                'retention' => [
                    'strategy' => $policy->strategy,
                    'keep_latest' => $policy->keepLatest,
                    'max_age_days' => $policy->maxAgeDays,
                    'inherited' => $definition->retention === null,
                ],
            ];
        }

        return ToolResult::ok(
            sprintf('%d profile(s); the default is [%s].', count($profiles), (string) $this->config->get('backup-manager.default', 'default')),
            ['default' => (string) $this->config->get('backup-manager.default', 'default'), 'profiles' => $profiles],
        );
    }
}
