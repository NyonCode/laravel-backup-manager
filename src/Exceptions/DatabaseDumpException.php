<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

use Throwable;

/**
 * Thrown when a database dump or restore process fails.
 */
final class DatabaseDumpException extends BackupManagerException
{
    public static function processFailed(string $connection, string $error, ?Throwable $previous = null): self
    {
        return new self(
            "Database dump for connection [{$connection}] failed: {$error}",
            previous: $previous
        );
    }

    /**
     * @param  string  ...$binaries  Every name that was tried, best first.
     */
    public static function binaryNotFound(string ...$binaries): self
    {
        // Naming every candidate matters for MySQL/MariaDB, where the same tool
        // ships under two names and the reader needs to know both were missing.
        $names = implode('] or [', $binaries);

        return new self(
            "The [{$names}] binary could not be found. Make sure it is installed and available in your PATH, "
            .'or configure its path via the backup-manager config.'
        );
    }

    public static function emptyDump(string $connection): self
    {
        return new self("Database dump for connection [{$connection}] produced an empty file.");
    }
}
