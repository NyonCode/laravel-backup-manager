<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Tools;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Enums\McpToolAccess;
use Nyoncode\BackupManager\Managers\RetentionManager;
use Nyoncode\BackupManager\Mcp\ToolResult;
use Nyoncode\BackupManager\Support\Bytes;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Applies the retention policy — or, in preview mode, only says what it would do.
 *
 * The same class is registered twice: once read-only as `backup_preview_retention`,
 * once as the real `backup_apply_retention`, which is a destructive tool and is
 * therefore not exposed at all unless the installation explicitly allows it. The
 * split exists so an assistant can always *show* what a policy would remove
 * without ever being able to remove it by accident.
 */
final class RetentionTool extends Tool
{
    public function __construct(
        private readonly ProfileResolver $resolver,
        private readonly RetentionManager $retention,
        private readonly SnapshotDriver $snapshots,
        private readonly Repository $config,
        private readonly bool $destructive = false,
    ) {}

    public function name(): string
    {
        return $this->destructive ? 'backup_apply_retention' : 'backup_preview_retention';
    }

    public function title(): string
    {
        return $this->destructive ? 'Apply retention' : 'Preview retention';
    }

    public function description(): string
    {
        return $this->destructive
            ? 'Deletes the backups that fall outside the retention policy, on every destination of the '
                .'profile. Defaults to a dry run; pass dry_run: false to actually delete. Deleted backups '
                .'cannot be recovered — confirm with the user first.'
            : 'Shows which backups the retention policy would delete on each destination of a profile, '
                .'and how much space that would free. Deletes nothing.';
    }

    public function access(): McpToolAccess
    {
        return $this->destructive ? McpToolAccess::Destructive : McpToolAccess::Read;
    }

    public function inputSchema(): array
    {
        $properties = [
            'profile' => $this->profileProperty(),
            'disk' => [
                'type' => 'string',
                'description' => 'Only act on this destination disk. Defaults to every destination of the profile.',
            ],
        ];

        if ($this->destructive) {
            $properties['dry_run'] = [
                'type' => 'boolean',
                'description' => 'Report what would be deleted without deleting it.',
                'default' => true,
            ];
        }

        return $this->schema($properties);
    }

    public function handle(array $arguments): ToolResult
    {
        $definition = $this->resolver->resolve($this->profile($arguments));
        $dryRun = ! $this->destructive || (bool) ($arguments['dry_run'] ?? true);

        $policy = RetentionPolicy::fromConfig(
            (array) $this->config->get('backup-manager.retention', []),
            $definition->retention,
        );

        $disk = is_string($arguments['disk'] ?? null) && $arguments['disk'] !== '' ? $arguments['disk'] : null;
        $disks = $disk !== null ? [$disk] : $definition->destinations;

        $report = [];
        $deleted = 0;
        $bytes = 0;

        foreach ($disks as $destination) {
            $snapshots = $this->snapshots->applyRetention($destination, $definition->path, $policy, $dryRun);
            $backups = array_merge(
                $this->retention->apply($destination, $definition->path, $policy, $dryRun),
                $snapshots->deletedSnapshots,
            );

            $deleted += count($backups);
            $bytes += array_sum(array_map(static fn (StoredBackup $backup): int => $backup->size, $backups));

            $report[$destination] = [
                'backups' => array_map(static fn (StoredBackup $backup): array => [
                    'name' => $backup->name,
                    'created_at' => $backup->createdAt->format(DATE_ATOM),
                    'size_human' => Bytes::human($backup->size),
                ], $backups),
                'reclaimed_blobs' => $snapshots->reclaimedBlobs,
                'reclaimed_blob_bytes' => $snapshots->reclaimedBytes,
            ];
        }

        return ToolResult::ok(
            sprintf(
                '%s %d backup(s) (%s) under the [%s] policy on %s.',
                $dryRun ? 'Would delete' : 'Deleted',
                $deleted,
                Bytes::human($bytes),
                $policy->strategy,
                implode(', ', $disks),
            ),
            [
                'profile' => $definition->profile,
                'dry_run' => $dryRun,
                'policy' => [
                    'strategy' => $policy->strategy,
                    'keep_latest' => $policy->keepLatest,
                    'max_age_days' => $policy->maxAgeDays,
                ],
                'destinations' => $report,
                'total_deleted' => $deleted,
                'total_bytes' => $bytes,
            ],
        );
    }
}
