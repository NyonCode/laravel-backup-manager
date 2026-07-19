<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\DTOs;

/**
 * Immutable options controlling the scope and safety of a restore.
 */
final readonly class RestoreOptions
{
    /**
     * @param  list<string>  $onlyPaths  Archive-relative path prefixes to restore (empty = all files).
     * @param  bool  $safe  Snapshot the current state first and roll back on failure.
     */
    public function __construct(
        public bool $restoreDatabases = true,
        public bool $restoreFiles = true,
        public array $onlyPaths = [],
        public bool $verifyChecksums = true,
        public bool $safe = true,
    ) {}

    public static function full(bool $verifyChecksums = true, bool $safe = true): self
    {
        return new self(true, true, [], $verifyChecksums, $safe);
    }

    public static function databasesOnly(bool $verifyChecksums = true, bool $safe = true): self
    {
        return new self(true, false, [], $verifyChecksums, $safe);
    }

    public static function filesOnly(bool $verifyChecksums = true, bool $safe = true): self
    {
        return new self(false, true, [], $verifyChecksums, $safe);
    }

    /**
     * Restore only files under the given absolute path prefixes.
     *
     * @param  list<string>  $paths
     */
    public static function selective(array $paths, bool $verifyChecksums = true, bool $safe = true): self
    {
        return new self(false, true, $paths, $verifyChecksums, $safe);
    }

    public function isSelective(): bool
    {
        return $this->onlyPaths !== [];
    }
}
