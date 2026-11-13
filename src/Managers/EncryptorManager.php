<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Nyoncode\BackupManager\Contracts\Encryption\Encryptor;
use Nyoncode\BackupManager\Encryption\NullEncryptor;
use Nyoncode\BackupManager\Encryption\OpenSslEncryptor;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Support\EncryptionKey;

/**
 * Resolves an encryptor for an algorithm, wiring in the configured key.
 */
final class EncryptorManager
{
    public function __construct(
        private readonly ?string $configuredKey,
        private readonly ?int $maxGcmBytes = null,
    ) {}

    /**
     * @param  bool  $allowLegacyCbc  Whether a pre-1.4, unauthenticated CBC archive may
     *                                be decrypted. Restore passes false unless the backup is
     *                                authenticated or the operator opted in.
     */
    public function for(EncryptionAlgorithm $algorithm, bool $allowLegacyCbc = true): Encryptor
    {
        if (! $algorithm->isEnabled()) {
            return new NullEncryptor;
        }

        return new OpenSslEncryptor(
            $algorithm,
            EncryptionKey::fromConfig($this->configuredKey),
            $this->maxGcmBytes,
            $allowLegacyCbc,
        );
    }
}
