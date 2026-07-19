<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Integrity;

use Nyoncode\BackupManager\ValueObjects\Checksum;

/**
 * Capability: produce and verify a keyed signature over a payload.
 */
interface Signer
{
    public function sign(string $payload): Checksum;

    public function verify(string $payload, Checksum $signature): bool;
}
