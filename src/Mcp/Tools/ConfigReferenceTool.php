<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\ConfigReference;
use Nyoncode\BackupManager\ValueObjects\ConfigOption;

/**
 * Hands the model the package's configuration schema, so it proposes real keys
 * with real values instead of plausible-looking ones.
 */
final class ConfigReferenceTool extends Tool
{
    public function __construct(private readonly ConfigReference $reference) {}

    public function name(): string
    {
        return 'backup_config_reference';
    }

    public function title(): string
    {
        return 'Configuration reference';
    }

    public function description(): string
    {
        return 'Every configuration key of the package: dot path, type, allowed values, default, the '
            .'environment variable that feeds it, and whether it needs the Pro package. These are the only '
            .'keys backup_configure accepts. `*` in a key stands for a profile name.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'search' => [
                'type' => 'string',
                'description' => 'Only return keys or descriptions containing this text (e.g. "retention", "encryption").',
            ],
        ]);
    }

    public function handle(array $arguments): ToolResult
    {
        $search = is_string($arguments['search'] ?? null) ? strtolower($arguments['search']) : null;

        $options = array_values(array_filter(
            $this->reference->options(),
            static fn (ConfigOption $option): bool => $search === null
                || str_contains(strtolower($option->key), $search)
                || str_contains(strtolower($option->description), $search)
                || ($option->env !== null && str_contains(strtolower($option->env), $search)),
        ));

        return ToolResult::ok(
            sprintf('%d configuration key(s).%s', count($options), $search === null ? '' : " Filtered by [{$search}]."),
            [
                'prefix' => 'backup-manager',
                'note' => 'Pass keys to backup_configure without the `backup-manager.` prefix; a value read through env() is written to .env automatically.',
                'options' => array_map(static fn (ConfigOption $option): array => $option->toArray(), $options),
            ],
        );
    }
}
