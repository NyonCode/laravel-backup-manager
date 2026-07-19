<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * The outcome of applying retention to a destination's incremental snapshots.
 *
 * Alongside the pruned snapshots it records how much of the shared blob pool
 * was reclaimed — the blobs that no surviving snapshot references any more.
 */
final readonly class SnapshotRetentionResult
{
    /**
     * @param  list<StoredBackup>  $deletedSnapshots  Snapshots that were (or would be) removed.
     * @param  int  $reclaimedBlobs  Number of unreferenced blobs that were (or would be) swept.
     * @param  int  $reclaimedBytes  Total byte size of the reclaimed blobs.
     */
    public function __construct(
        public array $deletedSnapshots,
        public int $reclaimedBlobs,
        public int $reclaimedBytes,
    ) {}

    public static function empty(): self
    {
        return new self([], 0, 0);
    }
}
