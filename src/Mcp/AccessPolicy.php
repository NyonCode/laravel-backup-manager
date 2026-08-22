<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Enums\McpToolAccess;

/**
 * What the MCP server is allowed to do in this installation.
 *
 * The defaults are deliberately timid: an assistant may read everything, run and
 * verify backups, and — in a non-production environment — write configuration.
 * Deleting backups or restoring over a live application is never enabled by a
 * default, because a wrong call there is the one mistake this package exists to
 * prevent.
 */
final readonly class AccessPolicy
{
    /**
     * @param  list<string>  $allowedEnvironments  `['*']` allows every environment.
     */
    public function __construct(
        public bool $enabled,
        public bool $allowWrites,
        public bool $allowDestructive,
        public string $environment,
        public array $allowedEnvironments,
    ) {}

    public static function fromConfig(Repository $config, string $environment): self
    {
        /** @var array<int, string> $environments */
        $environments = (array) $config->get('backup-manager.mcp.environments', ['local']);

        return new self(
            enabled: (bool) $config->get('backup-manager.mcp.enabled', true),
            allowWrites: (bool) $config->get('backup-manager.mcp.allow_writes', true),
            allowDestructive: (bool) $config->get('backup-manager.mcp.allow_destructive', false),
            environment: $environment,
            allowedEnvironments: array_values(array_map('strval', $environments)),
        );
    }

    /**
     * Whether the server may serve at all.
     */
    public function usable(): bool
    {
        return $this->enabled && $this->environmentAllowed();
    }

    public function environmentAllowed(): bool
    {
        return in_array('*', $this->allowedEnvironments, true)
            || in_array($this->environment, $this->allowedEnvironments, true);
    }

    public function permits(McpToolAccess $access): bool
    {
        return match ($access) {
            McpToolAccess::Read, McpToolAccess::Operate => true,
            McpToolAccess::Configure => $this->allowWrites,
            McpToolAccess::Destructive => $this->allowDestructive,
        };
    }

    /**
     * A message explaining how to lift the restriction, or null when permitted.
     */
    public function denialReason(McpToolAccess $access): ?string
    {
        if ($this->permits($access)) {
            return null;
        }

        return match ($access) {
            McpToolAccess::Configure => 'Configuration writes are disabled. Set BACKUP_MCP_ALLOW_WRITES=true (or backup-manager.mcp.allow_writes) to let the assistant edit .env and config/backup-manager.php.',
            McpToolAccess::Destructive => 'Destructive operations are disabled. Set BACKUP_MCP_ALLOW_DESTRUCTIVE=true (or backup-manager.mcp.allow_destructive) to allow deleting backups from an assistant.',
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'environment' => $this->environment,
            'environment_allowed' => $this->environmentAllowed(),
            'allowed_environments' => $this->allowedEnvironments,
            'allow_writes' => $this->allowWrites,
            'allow_destructive' => $this->allowDestructive,
        ];
    }
}
