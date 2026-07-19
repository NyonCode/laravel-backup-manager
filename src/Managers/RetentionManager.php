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
 */
final class RetentionManager
{
    public function __construct(
        private readonly BackupRepository $repository,
        private readonly RetentionSelector $selector,
    ) {}

    /**
     * @return list<StoredBackup> The backups that were (or would be) deleted, newest first.
     */
    public function apply(string $disk, string $path, RetentionPolicy $policy, bool $dryRun = false): array
    {
        $backups = $this->repository->list($disk, $path);
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
