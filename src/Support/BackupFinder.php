<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Lists the backups on a destination, archives and snapshots together.
 *
 * Both kinds are restorable and both count as "the latest backup", so anything
 * that answers "what is stored here?" has to merge them — keeping that in one
 * place stops a caller from silently ignoring half the destination.
 */
final class BackupFinder
{
    public function __construct(private readonly BackupRepository $repository) {}

    /**
     * @return list<StoredBackup> Newest first.
     */
    public function all(string $disk, string $path): array
    {
        $backups = array_merge(
            $this->repository->list($disk, $path),
            $this->repository->listSnapshots($disk, $path),
        );

        usort($backups, static fn (StoredBackup $a, StoredBackup $b): int => $b->createdAt <=> $a->createdAt);

        return $backups;
    }

    /**
     * Find a backup by name, or the newest one when no name is given.
     */
    public function find(string $disk, string $path, ?string $name): ?StoredBackup
    {
        $backups = $this->all($disk, $path);

        if ($name === null) {
            return $backups[0] ?? null;
        }

        foreach ($backups as $backup) {
            if ($backup->name === $name) {
                return $backup;
            }
        }

        return null;
    }
}
