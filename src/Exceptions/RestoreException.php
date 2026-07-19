<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

use Throwable;

/**
 * Thrown when a restore operation cannot be completed.
 */
final class RestoreException extends BackupManagerException
{
    public static function backupNotFound(string $identifier): self
    {
        return new self("Backup [{$identifier}] could not be found in the configured destination.");
    }

    public static function failed(string $reason, ?Throwable $previous = null): self
    {
        return new self("Restore failed: {$reason}", previous: $previous);
    }
}
