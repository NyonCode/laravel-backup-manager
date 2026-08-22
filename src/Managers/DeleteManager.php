<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Illuminate\Contracts\Events\Dispatcher;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Events\BackupDeleted;
use Nyoncode\BackupManager\Exceptions\DeletionException;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Deletes named backups from a destination, on request rather than by policy.
 *
 * {@see RetentionManager} prunes whatever falls outside a policy; this removes
 * exactly what the caller named — a corrupted archive, a run kept by mistake.
 * Because the caller chooses, the guard rails are here rather than in the
 * policy: the deletion is refused outright if it would leave the destination
 * with no backup at all, and nothing is removed until every named backup has
 * been resolved, so a typo cannot delete half a selection.
 *
 * Deleting an incremental snapshot removes its manifest only. The blobs it
 * referenced stay in the pool until a retention run garbage-collects them,
 * because other snapshots may still share them — reclaiming space is never
 * worth risking another backup's contents.
 */
final class DeleteManager
{
    public function __construct(
        private readonly BackupRepository $repository,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Delete the named backups from one destination.
     *
     * @param  list<string>  $names  Backup names as listed on the destination.
     * @param  string|null  $actor  Who requested this, for the audit trail.
     * @return list<StoredBackup> The backups that were (or would be) deleted, newest first.
     *
     * @throws DeletionException When a name is unknown, or the deletion would
     *                           empty the destination.
     */
    public function delete(string $disk, string $path, array $names, ?string $actor = null, bool $dryRun = false): array
    {
        if ($names === []) {
            return [];
        }

        $stored = $this->storedOn($disk, $path);
        $delete = $this->resolve($stored, $names, $disk);

        // Fail closed: a destination that still holds nothing is indistinguishable
        // from one that was never backed up.
        if (count($delete) >= count($stored)) {
            throw DeletionException::wouldLeaveNoBackups($disk);
        }

        if ($dryRun) {
            return $delete;
        }

        foreach ($delete as $backup) {
            $this->repository->delete($backup);
            $this->events->dispatch(new BackupDeleted($backup, $actor));
        }

        return $delete;
    }

    /**
     * Every backup on the destination, archives and snapshots alike, newest first.
     *
     * @return list<StoredBackup>
     */
    private function storedOn(string $disk, string $path): array
    {
        $stored = array_merge(
            $this->repository->list($disk, $path),
            $this->repository->listSnapshots($disk, $path),
        );

        usort($stored, static fn (StoredBackup $a, StoredBackup $b): int => $b->createdAt <=> $a->createdAt);

        return $stored;
    }

    /**
     * Resolve every name up front, so an unknown one aborts before any deletion.
     *
     * @param  list<StoredBackup>  $stored
     * @param  list<string>  $names
     * @return list<StoredBackup>
     *
     * @throws DeletionException
     */
    private function resolve(array $stored, array $names, string $disk): array
    {
        $byName = [];
        foreach ($stored as $backup) {
            $byName[$backup->name] = $backup;
        }

        $resolved = [];
        foreach (array_unique($names) as $name) {
            if (! isset($byName[$name])) {
                throw DeletionException::notFound($name, $disk);
            }

            $resolved[] = $byName[$name];
        }

        return $resolved;
    }
}
