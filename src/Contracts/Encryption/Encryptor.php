<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Encryption;

use Nyoncode\BackupManager\Exceptions\EncryptionException;

/**
 * Capability: encrypt and decrypt a file on disk.
 */
interface Encryptor
{
    /**
     * @throws EncryptionException
     */
    public function encrypt(string $source, string $destination): void;

    /**
     * @throws EncryptionException
     */
    public function decrypt(string $source, string $destination): void;
}
