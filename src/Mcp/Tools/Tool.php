<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Nyoncode\BackupManager\Contracts\Mcp\McpTool;
use Nyoncode\BackupManager\Enums\McpToolAccess;

/**
 * Shared plumbing for the built-in tools: schema helpers and a read-only default.
 */
abstract class Tool implements McpTool
{
    public function access(): McpToolAccess
    {
        return McpToolAccess::Read;
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return $this->schema();
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    protected function schema(array $properties = [], array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties === [] ? (object) [] : $properties,
            'additionalProperties' => false,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * The profile argument every profile-scoped tool accepts.
     *
     * @return array<string, mixed>
     */
    protected function profileProperty(): array
    {
        return [
            'type' => 'string',
            'description' => 'Backup profile to act on. Defaults to the configured default profile.',
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function profile(array $arguments): ?string
    {
        $profile = $arguments['profile'] ?? null;

        return is_string($profile) && $profile !== '' ? $profile : null;
    }
}
