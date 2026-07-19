<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * A single file discovered by the collector, ready to be placed in an archive.
 *
 * The archive path mirrors the file's absolute location (with the leading
 * separator stripped and a `files/` prefix) so entries are unambiguous even
 * when several source roots are combined, and can be mapped back on restore.
 */
final readonly class CollectedFile
{
    public function __construct(
        public string $absolutePath,
        public string $archivePath,
        public int $size,
        public bool $isSymlink = false,
        public ?string $symlinkTarget = null,
    ) {}
}
