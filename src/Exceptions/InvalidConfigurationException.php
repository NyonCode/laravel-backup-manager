<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

/**
 * Thrown when the package is misconfigured.
 */
final class InvalidConfigurationException extends BackupManagerException
{
    public static function unknownProfile(string $profile): self
    {
        return new self("Unknown backup profile [{$profile}]. Check your backup-manager config.");
    }

    public static function unsafeTableName(string $key, string $table): self
    {
        return new self(sprintf(
            'The table name [%s] in databases.%s is not accepted: a name may not be empty, start with "-" or contain control characters.',
            addcslashes($table, "\0..\37"),
            $key,
        ));
    }

    public static function missingKey(string $key): self
    {
        return new self("Missing required configuration key [{$key}].");
    }
}
