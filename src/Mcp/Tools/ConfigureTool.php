<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Arr;
use Nyoncode\BackupManager\Enums\McpToolAccess;
use Nyoncode\BackupManager\Exceptions\ConfigWriteException;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\ConfigReference;
use Nyoncode\BackupManager\Support\ConfigValidator;
use Nyoncode\BackupManager\Support\ConfigWriter;
use Nyoncode\BackupManager\ValueObjects\ConfigFinding;

/**
 * Changes the application's backup configuration.
 *
 * The call is a dry run unless told otherwise, and a dry run returns two things:
 * the exact edits that would be made, and the findings the configuration would
 * *then* have — so a mistake is visible before it is written rather than after
 * the next backup fails. A change set that would leave the configuration with
 * errors is refused outright unless the caller insists with `force`.
 */
final class ConfigureTool extends Tool
{
    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
        private readonly ConfigWriter $writer,
        private readonly ConfigValidator $validator,
        private readonly ConfigReference $reference,
    ) {}

    public function name(): string
    {
        return 'backup_configure';
    }

    public function title(): string
    {
        return 'Configure backups';
    }

    public function description(): string
    {
        return 'Writes backup configuration: `config` takes dot paths from backup_config_reference '
            .'(e.g. {"profiles.default.destinations": ["s3"], "retention.simple.keep_latest": 14}), `env` takes '
            .'this package\'s environment variables (e.g. {"BACKUP_ENCRYPTION": "aes-256-cbc"}). Defaults to a '
            .'dry run that returns the exact edits plus the findings the result would have; call again with '
            .'dry_run: false to apply. Keys are validated against the reference, values that live in the '
            .'environment are routed to .env, comments in the config file are preserved, and the previous '
            .'config file is copied aside before it is changed.';
    }

    public function access(): McpToolAccess
    {
        return McpToolAccess::Configure;
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'config' => [
                'type' => 'object',
                'description' => 'Configuration keys to set, as dot paths below `backup-manager.` mapped to their new values.',
            ],
            'env' => [
                'type' => 'object',
                'description' => 'Environment variables of this package to set in .env, e.g. {"BACKUP_SCHEDULE": true}.',
            ],
            'dry_run' => [
                'type' => 'boolean',
                'description' => 'Preview the change without writing anything.',
                'default' => true,
            ],
            'force' => [
                'type' => 'boolean',
                'description' => 'Apply even when the resulting configuration still has errors. Use only when the user has been told what is wrong.',
                'default' => false,
            ],
        ]);
    }

    public function handle(array $arguments): ToolResult
    {
        /** @var array<string, mixed> $config */
        $config = (array) ($arguments['config'] ?? []);
        /** @var array<string, mixed> $env */
        $env = (array) ($arguments['env'] ?? []);
        $dryRun = (bool) ($arguments['dry_run'] ?? true);
        $force = (bool) ($arguments['force'] ?? false);

        if ($config === [] && $env === []) {
            return ToolResult::failed('Nothing to change: pass `config` and/or `env`. Call backup_config_reference for the keys.');
        }

        try {
            $projected = $this->project($config, $env);
        } catch (ConfigWriteException $exception) {
            return ToolResult::failed($exception->getMessage(), ['hint' => 'Call backup_config_reference for the keys this package accepts.']);
        }

        $findings = $this->validator->validate($projected);
        $errors = array_values(array_filter($findings, static fn (ConfigFinding $f): bool => $f->severity->value === 'error'));

        if (! $dryRun && $errors !== [] && ! $force) {
            return ToolResult::failed(
                sprintf('Refused: the resulting configuration would still have %d error(s). Fix them, or repeat with force: true.', count($errors)),
                ['resulting_findings' => ValidateConfigurationTool::report($findings)],
            );
        }

        try {
            $result = $this->writer->apply($config, $env, $dryRun);
        } catch (ConfigWriteException $exception) {
            return ToolResult::failed($exception->getMessage(), [
                'hint' => 'Nothing was written. Call backup_config_reference for the keys this package accepts.',
            ]);
        }

        $notes = $result['notes'];

        if (! $dryRun) {
            // The process that just wrote the files is still running on the old
            // values; mirror them so a follow-up backup_run uses what was asked for.
            $this->mirror($config, $env);

            if (method_exists($this->app, 'configurationIsCached') && $this->app->configurationIsCached()) {
                $notes[] = 'The application config is cached: run `php artisan config:clear` (or `config:cache`) before the change takes effect for other processes.';
            }
        }

        if ($result['changes'] === []) {
            return ToolResult::ok('No change: the configuration already holds those values.', [
                'dry_run' => $dryRun,
                'resulting_findings' => ValidateConfigurationTool::report($findings),
            ]);
        }

        return ToolResult::ok(
            $dryRun
                ? sprintf('Dry run: %d change(s) would be written. Review the findings, then call again with dry_run: false.', count($result['changes']))
                : sprintf('Applied %d change(s).', count($result['changes'])),
            [
                'dry_run' => $dryRun,
                'changes' => $result['changes'],
                'files' => $result['files'],
                'notes' => $notes,
                'resulting_findings' => ValidateConfigurationTool::report($findings),
            ],
        );
    }

    /**
     * The configuration as it would be after the change, without touching the
     * live one — this is what gets validated before anything is written.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $env
     */
    private function project(array $config, array $env): Repository
    {
        /** @var array<string, mixed> $all */
        $all = $this->config->all();

        foreach ($config as $path => $value) {
            $path = (string) $path;

            if ($this->reference->find($path) === null) {
                throw ConfigWriteException::unknownKey($path, $this->reference->suggest($path));
            }

            Arr::set($all, 'backup-manager.'.$path, $value);
        }

        foreach ($env as $variable => $value) {
            $option = $this->reference->findByEnv((string) $variable)
                ?? throw ConfigWriteException::unknownEnvironmentVariable((string) $variable);

            Arr::set($all, 'backup-manager.'.$option->key, $value);
        }

        return new ConfigRepository($all);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $env
     */
    private function mirror(array $config, array $env): void
    {
        foreach ($config as $path => $value) {
            $this->config->set('backup-manager.'.$path, $value);
        }

        foreach ($env as $variable => $value) {
            $option = $this->reference->findByEnv((string) $variable);

            if ($option !== null) {
                $this->config->set('backup-manager.'.$option->key, $value);
            }
        }
    }
}
