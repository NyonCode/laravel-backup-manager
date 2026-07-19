<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Storage;

use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Capability: enumerate and manage backups living on a storage destination.
 */
interface BackupRepository
{
    /**
     * List the self-contained archive backups under $path on $disk, newest first.
     *
     * Incremental snapshots are not included; see {@see listSnapshots()}.
     *
     * @return list<StoredBackup>
     */
    public function list(string $disk, string $path): array;

    /**
     * List the incremental snapshots under $path on $disk, newest first.
     *
     * @return list<StoredBackup>
     */
    public function listSnapshots(string $disk, string $path): array;

    public function readManifest(StoredBackup $backup): Manifest;

    /**
     * Stream the backup archive to a local file path.
     */
    public function download(StoredBackup $backup, string $localPath): void;

    /**
     * Delete the archive and its manifest sidecar.
     */
    public function delete(StoredBackup $backup): void;
}
