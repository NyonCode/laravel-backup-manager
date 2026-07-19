<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

/**
 * Thrown when encryption or decryption of a backup archive fails.
 */
final class EncryptionException extends BackupManagerException
{
    public static function missingKey(): self
    {
        return new self(
            'No encryption key configured. Set BACKUP_ENCRYPTION_KEY or disable encryption in the config.'
        );
    }

    public static function invalidKeyLength(int $expected, int $actual): self
    {
        return new self("Encryption key must be {$expected} bytes, got {$actual} bytes.");
    }

    public static function cipherFailed(string $reason): self
    {
        return new self("Encryption cipher operation failed: {$reason}");
    }

    public static function authenticationFailed(): self
    {
        return new self('Decryption failed: authentication tag mismatch. The archive may be corrupt or tampered with.');
    }

    public static function gcmArchiveTooLarge(int $size, int $limit): self
    {
        return new self(sprintf(
            'Archive is %d bytes, which exceeds the AES-256-GCM in-memory limit of %d bytes. '
            .'GCM buffers the whole archive; use aes-256-cbc for large backups, or raise '
            .'backup-manager.encryption.gcm_max_megabytes.',
            $size,
            $limit,
        ));
    }
}
