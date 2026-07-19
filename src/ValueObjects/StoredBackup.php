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
    ) {}

    public function isSnapshot(): bool
    {
        return $this->mode->isIncremental();
    }

    /**
     * The manifest sidecar file name derived from an archive file name.
     */
    public static function manifestNameFor(string $archiveName): string
    {
        return preg_replace('/\.tar(\.[A-Za-z0-9]+)*$/', '', $archiveName).'.manifest.json';
    }
}
