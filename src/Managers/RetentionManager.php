<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Support\RetentionSelector;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Applies a retention policy to the self-contained archive backups on a
 * destination.
 *
 * The keep-set is computed by {@see RetentionSelector} (strategy plus age and
 * size caps); everything outside it is deleted. Deletions can be previewed via
 * the dry-run flag before anything is removed. Incremental snapshots — whose
 * deletion must reference-count a shared blob pool — are handled separately by
 * {@see SnapshotRetentionManager}.
 *
 * A destination is shared: every profile defaults to the same `backups` path, so
 * one disk and path routinely hold several profiles' archives. Passing the
 * profile scopes the whole operation to its own backups — they are the only ones
 * counted towards the keep-set and the only ones eligible for deletion — because
 * a policy written for nightly snapshots must never get a vote on the fortnightly
 * full backups sitting beside them.
 */
final class RetentionManager
{
    public function __construct(
        private readonly BackupRepository $repository,
        private readonly RetentionSelector $selector,
    ) {}

    /**
     * @param  string|null  $profile  Scope to this profile's backups; null considers every backup in the path.
     * @return list<StoredBackup> The backups that were (or would be) deleted, newest first.
     */
    public function apply(
        string $disk,
        string $path,
        RetentionPolicy $policy,
        bool $dryRun = false,
        ?string $profile = null,
    ): array {
        // Filtered before the selector sees them: a foreign backup must not
        // occupy a slot in `keep_latest`, nor be measured against a size cap it
        // does not contribute to.
        $backups = array_values(array_filter(
            $this->repository->list($disk, $path),
            static fn (StoredBackup $backup): bool => $backup->belongsTo($profile),
        ));

        $keep = $this->selector->keep($backups, $policy);

        $keptPaths = array_fill_keys(array_map(static fn (StoredBackup $b): string => $b->archivePath, $keep), true);

        $delete = array_values(array_filter(
            $backups,
            static fn (StoredBackup $backup): bool => ! isset($keptPaths[$backup->archivePath]),
        ));

        if (! $dryRun) {
            foreach ($delete as $backup) {
                $this->repository->delete($backup);
            }
        }

        return $delete;
    }
}
