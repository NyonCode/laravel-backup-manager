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
     * @param  bool  $allowLegacyCbc  Whether a pre-1.3, unauthenticated CBC archive may
     *                                be decrypted. Callers that know the archive was written
     *                                by 1.3 or later pass false, so it cannot be downgraded.
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
