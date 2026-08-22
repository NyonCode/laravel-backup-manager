<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\BackupFinder;
use Nyoncode\BackupManager\Support\Bytes;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * What is actually stored on a destination — the only honest answer to "are we
 * backed up?".
 */
final class ListBackupsTool extends Tool
{
    public function __construct(
        private readonly ProfileResolver $resolver,
        private readonly BackupFinder $finder,
    ) {}

    public function name(): string
    {
        return 'backup_list_backups';
    }

    public function title(): string
    {
        return 'List stored backups';
    }

    public function description(): string
    {
        return 'Lists the backups stored for a profile — archives and incremental snapshots, newest '
            .'first — with their size and age. Read-only.';
    }

    public function inputSchema(): array
    {
        return $this->schema([
            'profile' => $this->profileProperty(),
            'disk' => [
                'type' => 'string',
                'description' => 'Destination disk to inspect. Defaults to the profile\'s first destination.',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'How many backups to return.',
                'default' => 20,
            ],
        ]);
    }

    public function handle(array $arguments): ToolResult
    {
        $definition = $this->resolver->resolve($this->profile($arguments));
        $disk = is_string($arguments['disk'] ?? null) && $arguments['disk'] !== ''
            ? $arguments['disk']
            : ($definition->destinations[0] ?? 'local');
        $limit = max(1, (int) ($arguments['limit'] ?? 20));

        $backups = $this->finder->all($disk, $definition->path);
        $total = array_sum(array_map(static fn (StoredBackup $backup): int => $backup->size, $backups));
        $newest = $backups[0] ?? null;

        return ToolResult::ok(
            $backups === []
                ? sprintf('No backups on disk [%s] under [%s] for profile [%s].', $disk, $definition->path, $definition->profile)
                : sprintf(
                    '%d backup(s) on [%s], %s in total; newest is %s old.',
                    count($backups),
                    $disk,
                    Bytes::human($total),
                    $this->age($newest?->createdAt->getTimestamp() ?? time()),
                ),
            [
                'profile' => $definition->profile,
                'disk' => $disk,
                'path' => $definition->path,
                'count' => count($backups),
                'total_bytes' => $total,
                'total_human' => Bytes::human($total),
                'backups' => array_map(static fn (StoredBackup $backup): array => [
                    'name' => $backup->name,
                    'mode' => $backup->mode->value,
                    'created_at' => $backup->createdAt->format(DATE_ATOM),
                    'size_bytes' => $backup->size,
                    'size_human' => Bytes::human($backup->size),
                ], array_slice($backups, 0, $limit)),
            ],
        );
    }

    private function age(int $timestamp): string
    {
        $seconds = max(0, time() - $timestamp);

        return match (true) {
            $seconds < 3600 => sprintf('%d minute(s)', intdiv($seconds, 60)),
            $seconds < 86400 => sprintf('%d hour(s)', intdiv($seconds, 3600)),
            default => sprintf('%d day(s)', intdiv($seconds, 86400)),
        };
    }
}
