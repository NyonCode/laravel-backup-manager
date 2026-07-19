<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * Hashing algorithms used for integrity checksums.
 */
enum ChecksumAlgorithm: string
{
    case Sha256 = 'sha256';
    case Sha512 = 'sha512';

    /**
     * The hash algorithm name understood by hash_* functions.
     */
    public function hashName(): string
    {
        return $this->value;
    }
}
