<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Enums\McpToolAccess;
use Nyoncode\BackupManager\Jobs\RunBackupJob;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\Bytes;

/**
 * Creates a backup — the only way to know a configuration works.
 *
 * It runs in the calling process by default, because an assistant that has just
 * configured something needs the outcome, not a queued job id it cannot follow.
 */
final class RunBackupTool extends Tool
{
    public function __construct(
        private readonly BackupManager $manager,
        private readonly Dispatcher $bus,
        private readonly Repository $config,
    ) {}

    public function name(): string
    {
        return 'backup_run';
    }

    public function title(): string
    {
        return 'Run a backup';
    }

    public function description(): string
    {
        return 'Creates a backup for a profile and reports what was produced: archive name, size, '
            .'destinations, databases and files covered. Runs synchronously, so a large backup takes as long '
            .'as it takes; pass queue: true to dispatch it to the queue instead. Creates data, deletes nothing.';
    }

    public function access(): McpToolAccess
    {
        return McpToolAccess::Operate;
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'profile' => $this->profileProperty(),
            'queue' => [
                'type' => 'boolean',
                'description' => 'Dispatch to the queue instead of running now. Requires a worker; returns immediately without a result.',
                'default' => false,
            ],
        ]);
    }

    public function handle(array $arguments): ToolResult
    {
        $profile = $this->profile($arguments);
        $label = $profile ?? (string) $this->config->get('backup-manager.default', 'default');

        if ((bool) ($arguments['queue'] ?? false)) {
            $this->bus->dispatch(new RunBackupJob($profile));

            return ToolResult::ok(
                sprintf('Backup for profile [%s] dispatched to the queue; a worker must pick it up.', $label),
                ['profile' => $label, 'queued' => true],
            );
        }

        $started = microtime(true);
        $result = $this->manager->run($profile);
        $manifest = $result->manifest;

        return ToolResult::ok(
            sprintf(
                'Backup [%s] created for profile [%s]: %s, %d database(s), %d file(s), stored on %s.',
                $result->archiveFileName,
                $result->profile,
                Bytes::human($result->archiveSize),
                count($manifest->databases),
                $manifest->fileCount(),
                implode(', ', $result->destinations),
            ),
            [
                'profile' => $result->profile,
                'queued' => false,
                'archive' => $result->archiveFileName,
                'mode' => $manifest->mode->value,
                'size_bytes' => $result->archiveSize,
                'size_human' => Bytes::human($result->archiveSize),
                'databases' => count($manifest->databases),
                'files' => $manifest->fileCount(),
                'compression' => $manifest->compression->value,
                'encryption' => $manifest->encryption->value,
                'signed' => $manifest->isSigned(),
                'destinations' => $result->destinations,
                'duration_seconds' => round(microtime(true) - $started, 2),
                'next_step' => 'Call backup_verify to check the stored archive against its manifest.',
            ],
        );
    }
}
