<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\ValueObjects\CleanupReport;

/**
 * Applies a profile's retention policy across its destinations.
 *
 * Retention has two engines — {@see RetentionManager} for self-contained
 * archives, the {@see SnapshotDriver} for incremental snapshots and their blob
 * pool — and every caller needs both, in the same order, under the same policy.
 * This is where that sequence lives, so the CLI, the queue, the dashboard and
 * the MCP tool cannot drift apart on which backups a profile's policy governs.
 *
 * Everything is scoped to the profile: destinations share a path by default, so
 * without that scope one profile's policy would silently prune another's.
 */
final class CleanupManager
{
    public function __construct(
        private readonly RetentionManager $retention,
        private readonly SnapshotDriver $snapshots,
        private readonly Repository $config,
    ) {}

    /**
     * @param  string|null  $disk  Clean only this destination; null cleans every one of the profile's.
     * @return list<CleanupReport> One report per destination, in the profile's order.
     */
    public function clean(BackupDefinition $definition, ?string $disk = null, bool $dryRun = false): array
    {
        $policy = $this->policyFor($definition);
        $reports = [];

        foreach ($disk !== null ? [$disk] : $definition->destinations as $destination) {
            // Snapshots first: pruning their manifests before the sweep is what
            // lets the pool see the true set of survivors.
            $snapshots = $this->snapshots->applyRetention(
                $destination,
                $definition->path,
                $policy,
                $dryRun,
                $definition->profile,
            );

            $archives = $this->retention->apply(
                $destination,
                $definition->path,
                $policy,
                $dryRun,
                $definition->profile,
            );

            $reports[] = new CleanupReport(
                disk: $destination,
                deleted: array_merge($archives, $snapshots->deletedSnapshots),
                reclaimedBlobs: $snapshots->reclaimedBlobs,
                reclaimedBytes: $snapshots->reclaimedBytes,
            );
        }

        return $reports;
    }

    /**
     * The policy a profile is cleaned under: the package defaults with the
     * profile's own overrides folded in. Public so a caller that reports on a
     * run — the MCP tool naming the strategy it applied — reads the same policy
     * the run itself used.
     */
    public function policyFor(BackupDefinition $definition): RetentionPolicy
    {
        return RetentionPolicy::fromConfig(
            (array) $this->config->get('backup-manager.retention', []),
            $definition->retention,
        );
    }
}
