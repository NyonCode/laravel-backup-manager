<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * The outcome of verifying a stored backup's integrity.
 */
final readonly class VerificationResult
{
    public function __construct(
        public string $backupName,
        public bool $checksumValid,
        public ?bool $signatureValid,
        public Manifest $manifest,
    ) {}

    public function passed(): bool
    {
        return $this->checksumValid && $this->signatureValid !== false;
    }

    /**
     * Failed for carrying no signature at all, rather than a wrong one — a
     * backup taken before signing was enabled reads very differently from a
     * forged one, though both are refused.
     */
    public function unsigned(): bool
    {
        return $this->signatureValid === false && $this->manifest->signature === null;
    }
}
