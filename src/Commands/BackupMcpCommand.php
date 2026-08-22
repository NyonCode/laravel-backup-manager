<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Mcp\AccessPolicy;
use Nyoncode\BackupManager\Mcp\Server;
use Nyoncode\BackupManager\Mcp\StdioTransport;

/**
 * Serves the Model Context Protocol on stdin/stdout, so an AI assistant can set
 * up, inspect and exercise backups through the same code paths a person uses.
 *
 * Nothing may be printed to stdout but protocol frames — a client parses every
 * line — so all human-facing output here goes to stderr, which MCP clients
 * surface as server logs.
 */
final class BackupMcpCommand extends Command
{
    protected $signature = 'backup:mcp
        {--allow-writes : Allow configuration writes for this session}
        {--deny-writes : Forbid configuration writes for this session}
        {--allow-destructive : Allow deleting backups for this session}
        {--read-only : Expose only tools that change nothing}';

    protected $description = 'Serve the Backup Manager MCP server for an AI assistant (stdio)';

    public function handle(Repository $config): int
    {
        $this->applyOverrides($config);

        $policy = AccessPolicy::fromConfig($config, $this->laravel->environment());

        if (! $policy->enabled) {
            $this->stderr('backup:mcp is disabled (backup-manager.mcp.enabled). Set BACKUP_MCP=true to serve.');

            return self::FAILURE;
        }

        if (! $policy->environmentAllowed()) {
            $this->stderr(sprintf(
                'backup:mcp refuses to run in the [%s] environment. Allowed: %s (backup-manager.mcp.environments).',
                $policy->environment,
                implode(', ', $policy->allowedEnvironments),
            ));

            return self::FAILURE;
        }

        $server = $this->laravel->make(Server::class);

        $this->stderr(sprintf(
            'Backup Manager MCP server ready in [%s]: %d tool(s) — writes %s, destructive operations %s.',
            $policy->environment,
            count($server->tools()),
            $policy->allowWrites ? 'allowed' : 'disabled',
            $policy->allowDestructive ? 'ALLOWED' : 'disabled',
        ));

        foreach ($server->tools() as $tool) {
            $this->stderr(sprintf('  · %s (%s)', $tool->name(), $tool->access()->value));
        }

        (new StdioTransport($server))->run(STDIN, STDOUT);

        return self::SUCCESS;
    }

    /**
     * Command line flags win over configuration for this session only.
     */
    private function applyOverrides(Repository $config): void
    {
        if ($this->option('read-only')) {
            $config->set('backup-manager.mcp.allow_writes', false);
            $config->set('backup-manager.mcp.allow_destructive', false);

            return;
        }

        if ($this->option('allow-writes')) {
            $config->set('backup-manager.mcp.allow_writes', true);
        }

        if ($this->option('deny-writes')) {
            $config->set('backup-manager.mcp.allow_writes', false);
        }

        if ($this->option('allow-destructive')) {
            $config->set('backup-manager.mcp.allow_destructive', true);
        }
    }

    private function stderr(string $message): void
    {
        fwrite(STDERR, $message.PHP_EOL);
    }
}
