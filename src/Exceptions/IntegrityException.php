<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

/**
 * Thrown when an integrity or signature verification fails.
 */
final class IntegrityException extends BackupManagerException
{
    public static function checksumMismatch(string $path, string $expected, string $actual): self
    {
        return new self(
            "Integrity check failed for [{$path}]. Expected checksum {$expected}, computed {$actual}."
        );
    }

    public static function signatureMismatch(): self
    {
        return new self('Signature verification failed. The backup could not be authenticated.');
    }

    public static function signingRequiresPro(): self
    {
        return new self(
            'Manifest signing is enabled but no signer is available. Signing is a '
            .'Pro capability — install nyoncode/laravel-backup-manager-pro, or disable '
            .'backup-manager.integrity.signing.enabled.'
        );
    }
}
