<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Snapshot;

use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\SnapshotRetentionResult;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * The core's stand-in for the incremental engine when Pro is not installed.
 *
 * The core can still read and list snapshots (their manifests are plain JSON),
 * but creating, restoring or verifying one requires the Pro package, so those
 * operations fail with a clear message rather than a confusing error. Retention
 * is a safe no-op — the core never deletes pool content it doesn't understand.
 */
final class NullSnapshotDriver implements SnapshotDriver
{
    public function supported(): bool
    {
        return false;
    }

    public function backupStages(): array
    {
        throw self::unavailable();
    }

    public function materialize(StoredBackup $backup, Manifest $manifest, string $extractDir, RestoreOptions $options): void
    {
        throw self::unavailable();
    }

    public function verify(StoredBackup $backup, Manifest $manifest): bool
    {
        throw self::unavailable();
    }

    public function applyRetention(
        string $disk,
        string $path,
        RetentionPolicy $policy,
        bool $dryRun = false,
        ?string $profile = null,
    ): SnapshotRetentionResult {
        return SnapshotRetentionResult::empty();
    }

    private static function unavailable(): BackupManagerException
    {
        return new BackupManagerException(
            'Incremental backups require the nyoncode/laravel-backup-manager-pro package.',
        );
    }
}
