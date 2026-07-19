<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Exceptions\EncryptionException;

/**
 * Normalises a configured encryption key into exactly 32 raw bytes.
 *
 * Accepts raw 32-byte strings and `base64:`-prefixed values (matching
 * Laravel's APP_KEY convention). The key material is never logged or exposed
 * beyond this object.
 */
final readonly class EncryptionKey
{
    private const REQUIRED_BYTES = 32;

    private function __construct(
        public string $bytes,
    ) {}

    public static function fromConfig(?string $key): self
    {
        if ($key === null || $key === '') {
            throw EncryptionException::missingKey();
        }

        $raw = str_starts_with($key, 'base64:')
            ? (base64_decode(substr($key, 7), true) ?: '')
            : $key;

        if (strlen($raw) !== self::REQUIRED_BYTES) {
            throw EncryptionException::invalidKeyLength(self::REQUIRED_BYTES, strlen($raw));
        }

        return new self($raw);
    }
}
