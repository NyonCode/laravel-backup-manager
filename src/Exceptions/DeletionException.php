<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

/**
 * Thrown when a targeted deletion is refused before anything is removed.
 */
final class DeletionException extends BackupManagerException
{
    public static function wouldLeaveNoBackups(string $disk): self
    {
        return new self(
            "Refusing to delete: this would leave no backups on disk [{$disk}]. ".
            'Take a new backup first, or use retention if you intend to clear the destination.'
        );
    }

    public static function notFound(string $name, string $disk): self
    {
        return new self("Backup [{$name}] was not found on disk [{$disk}].");
    }

    public static function mixedDisks(): self
    {
        return new self('Refusing to delete: every backup in one deletion must live on the same disk.');
    }
}
