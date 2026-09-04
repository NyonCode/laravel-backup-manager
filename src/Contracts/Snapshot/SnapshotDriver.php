<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Snapshot;

use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\SnapshotRetentionResult;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Capability: the incremental (snapshot / deduplicated) backup engine.
 *
 * Incremental backups are a Pro feature. The free core ships a null driver that
 * reports the capability as unavailable and refuses snapshot operations with a
 * clear message; the Pro package binds the real, pool-backed driver. Everything
 * snapshot-specific — the content-addressed blob pool, the snapshot pipeline
 * stages, restore, verify and reference-counted retention — lives behind this
 * single contract, so the core stays free of it while still reading and listing
 * snapshots.
 */
interface SnapshotDriver
{
    /**
     * Whether incremental backups are available in this installation.
     */
    public function supported(): bool;

    /**
     * The ordered pipeline stages that create an incremental backup.
     *
     * @return list<BackupStage>
     */
    public function backupStages(): array;

    /**
     * Reassemble a snapshot's files into $extractDir from the blob pool, ready
     * for the shared restore machinery to reinstate.
     */
    public function materialize(StoredBackup $backup, Manifest $manifest, string $extractDir, RestoreOptions $options): void;

    /**
     * Verify a snapshot's file index and that every referenced blob is present
     * and, once decoded, matches its recorded checksum.
     */
    public function verify(StoredBackup $backup, Manifest $manifest): bool;

    /**
     * Prune snapshots by the retention policy and garbage-collect the pool.
     *
     * @param  string|null  $profile  Scope to this profile's snapshots; null considers every snapshot in the path.
     */
    public function applyRetention(
        string $disk,
        string $path,
        RetentionPolicy $policy,
        bool $dryRun = false,
        ?string $profile = null,
    ): SnapshotRetentionResult;
}
