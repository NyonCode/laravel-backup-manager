<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\DTOs;

/**
 * An immutable description of which files a backup should collect.
 */
final readonly class FileSource
{
    /**
     * @param  list<string>  $includePaths  Absolute directory or file paths to archive.
     * @param  list<string>  $excludePatterns  Absolute path prefixes or fnmatch globs to skip.
     */
    public function __construct(
        public array $includePaths,
        public array $excludePatterns = [],
        public bool $followSymlinks = false,
        public bool $includeHidden = true,
    ) {}

    public function hasIncludes(): bool
    {
        return $this->includePaths !== [];
    }
}
