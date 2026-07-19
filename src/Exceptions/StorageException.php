<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

use Throwable;

/**
 * Thrown when a backup cannot be written to or read from a storage destination.
 */
final class StorageException extends BackupManagerException
{
    public static function writeFailed(string $disk, string $path, ?Throwable $previous = null): self
    {
        return new self("Failed to write backup to disk [{$disk}] at [{$path}].", previous: $previous);
    }

    public static function readFailed(string $disk, string $path, ?Throwable $previous = null): self
    {
        return new self("Failed to read backup from disk [{$disk}] at [{$path}].", previous: $previous);
    }
}
