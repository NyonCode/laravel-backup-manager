<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Archive;

/**
 * Capability: assemble and read a backup archive.
 */
interface Archiver
{
    /**
     * Create an archive at $destination.
     *
     * @param  array<string, string>  $fileEntries  Map of archive path => absolute source path.
     * @param  array<string, string>  $stringEntries  Map of archive path => literal contents (e.g. the manifest).
     */
    public function create(array $fileEntries, array $stringEntries, string $destination): void;

    /**
     * Extract the whole archive into a directory.
     */
    public function extractAll(string $archive, string $destination): void;

    /**
     * Extract only the entries whose path begins with one of the given prefixes.
     *
     * @param  list<string>  $prefixes
     */
    public function extractPaths(string $archive, array $prefixes, string $destination): void;

    /**
     * Read a single entry's contents without extracting the archive.
     */
    public function read(string $archive, string $entry): string;

    public function has(string $archive, string $entry): bool;
}
