<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

/**
 * Thrown when a configuration change cannot be written safely.
 *
 * Every factory here describes a refusal, not a failure half-way through: the
 * writer validates the whole change set first and only then touches a file, so
 * these are raised before anything on disk has moved.
 */
final class ConfigWriteException extends BackupManagerException
{
    public static function envFileMissing(string $path): self
    {
        return new self("No .env file at [{$path}]; refusing to create one.");
    }

    public static function unwritable(string $path): self
    {
        return new self("Unable to write [{$path}]. Check the file permissions.");
    }

    public static function unknownKey(string $key, ?string $suggestion = null): self
    {
        return new self(
            "Unknown configuration key [{$key}]."
            .($suggestion !== null ? " Did you mean [{$suggestion}]?" : '')
        );
    }

    public static function invalidValue(string $key, string $type, mixed $value): self
    {
        return new self(sprintf(
            'Configuration key [%s] expects %s, %s given.',
            $key,
            $type,
            get_debug_type($value),
        ));
    }

    public static function notAllowedValue(string $key, string $value, string $allowed): self
    {
        return new self("Configuration key [{$key}] does not accept [{$value}]. Allowed: {$allowed}.");
    }

    public static function unknownEnvironmentVariable(string $variable): self
    {
        return new self("[{$variable}] is not a Backup Manager environment variable; refusing to write it.");
    }

    public static function pathNotEditable(string $key, string $file): self
    {
        return new self(
            "The key [{$key}] could not be located unambiguously in [{$file}], so nothing was written. "
            .'Edit the file by hand, or move the setting to .env.'
        );
    }

    public static function invalidResult(string $file, string $details): self
    {
        return new self("Editing [{$file}] would have produced an invalid file, so the change was rolled back: {$details}");
    }
}
