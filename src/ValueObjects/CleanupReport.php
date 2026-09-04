<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * What applying a retention policy did (or would do) to one destination.
 *
 * Covers both engines: `deleted` holds the archives and the snapshot manifests
 * that fell outside the policy, while the reclaimed figures describe the blob
 * pool sweep that followed. On a dry run nothing was touched and every figure
 * describes what a real run would remove.
 */
final readonly class CleanupReport
{
    /**
     * @param  list<StoredBackup>  $deleted  Archives and snapshots removed, newest first.
     */
    public function __construct(
        public string $disk,
        public array $deleted,
        public int $reclaimedBlobs = 0,
        public int $reclaimedBytes = 0,
    ) {}

    public function deletedCount(): int
    {
        return count($this->deleted);
    }

    /**
     * The size of what was removed. Snapshots report their manifest's recorded
     * size, so this is the logical size freed, not the bytes the pool gave back
     * — {@see $reclaimedBytes} is that figure.
     */
    public function deletedBytes(): int
    {
        return array_sum(array_map(static fn (StoredBackup $backup): int => $backup->size, $this->deleted));
    }
}
