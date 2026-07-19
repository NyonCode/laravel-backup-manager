<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * Supported symmetric encryption algorithms for backup archives.
 */
enum EncryptionAlgorithm: string
{
    case None = 'none';
    case Aes256Cbc = 'aes-256-cbc';
    case Aes256Gcm = 'aes-256-gcm';

    public function isEnabled(): bool
    {
        return $this !== self::None;
    }

    /**
     * The OpenSSL cipher identifier.
     */
    public function cipher(): string
    {
        return $this->value;
    }

    /**
     * Length in bytes of the initialization vector for this cipher.
     */
    public function ivLength(): int
    {
        return match ($this) {
            self::None => 0,
            self::Aes256Cbc => 16,
            self::Aes256Gcm => 12,
        };
    }

    /**
     * Whether the cipher produces an authentication tag (AEAD).
     */
    public function isAuthenticated(): bool
    {
        return $this === self::Aes256Gcm;
    }
}
