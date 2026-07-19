<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

use Throwable;

/**
 * Thrown when a backup manifest cannot be built, read or parsed.
 */
final class ManifestException extends BackupManagerException
{
    public static function invalidJson(string $reason, ?Throwable $previous = null): self
    {
        return new self("Backup manifest contains invalid JSON: {$reason}", previous: $previous);
    }

    public static function missingKey(string $key): self
    {
        return new self("Backup manifest is missing the required key [{$key}].");
    }

    public static function notFound(string $path): self
    {
        return new self("Backup manifest could not be found at [{$path}].");
    }
}
