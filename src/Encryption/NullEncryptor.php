<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Encryption;

use Nyoncode\BackupManager\Contracts\Encryption\Encryptor;

/**
 * A no-op encryptor used when encryption is disabled.
 */
final class NullEncryptor implements Encryptor
{
    public function encrypt(string $source, string $destination): void
    {
        $this->copy($source, $destination);
    }

    public function decrypt(string $source, string $destination): void
    {
        $this->copy($source, $destination);
    }

    private function copy(string $source, string $destination): void
    {
        if ($source !== $destination) {
            copy($source, $destination);
        }
    }
}
