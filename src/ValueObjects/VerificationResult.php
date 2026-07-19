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
}
