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

    public static function binaryNotFound(string $binary): self
    {
        return new self(
            "The [{$binary}] binary could not be found. Make sure it is installed and available in your PATH, "
            .'or configure its path via the backup-manager config.'
        );
    }

    public static function emptyDump(string $connection): self
    {
        return new self("Database dump for connection [{$connection}] produced an empty file.");
    }
}
