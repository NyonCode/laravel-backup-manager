<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

use DateTimeImmutable;
use Nyoncode\BackupManager\Enums\BackupMode;

/**
 * A backup that exists on a storage destination.
 *
 * Describes where the archive and its manifest sidecar live, when it was
 * taken and how large it is, without eagerly loading the manifest itself.
 *
 * An incremental snapshot has no single archive: its `archivePath` is empty
 * and its content lives in the shared blob pool referenced by the manifest.
 *
 * The `profile` is the one the backup was taken under, read from the manifest's
 * config snapshot. Two profiles may share a disk and a path — the default `path`
 * is the same for all of them — so it is what tells their backups apart.
 */
final readonly class StoredBackup
{
    public function __construct(
        public string $disk,
        public string $archivePath,
        public string $manifestPath,
        public string $name,
        public int $size,
        public DateTimeImmutable $createdAt,
        public BackupMode $mode = BackupMode::Archive,
        public ?string $profile = null,
    ) {}

    public function isSnapshot(): bool
    {
        return $this->mode->isIncremental();
    }

    /**
     * Whether this backup is one the given profile owns.
     *
     * Fail-open on both sides: a null $profile asks about no profile in
     * particular, and a backup whose manifest predates profile tagging (or
     * cannot be read at all) answers yes. Retention is the caller that matters
     * here, and an upgrade must not quietly stop pruning the backups it was
     * pruning yesterday — leaving one profile's archives to another's policy is
     * the bug we already had, while sparing them forever is a disk that fills
     * up silently.
     */
    public function belongsTo(?string $profile): bool
    {
        return $profile === null
            || $this->profile === null
            || $this->profile === $profile;
    }

    /**
     * The manifest sidecar file name derived from an archive file name.
     */
    public static function manifestNameFor(string $archiveName): string
    {
        return preg_replace('/\.tar(\.[A-Za-z0-9]+)*$/', '', $archiveName).'.manifest.json';
    }
}
