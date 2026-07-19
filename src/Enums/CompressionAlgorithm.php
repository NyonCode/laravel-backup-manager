<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * Supported archive compression algorithms.
 */
enum CompressionAlgorithm: string
{
    case None = 'none';
    case Gzip = 'gzip';
    case Bzip2 = 'bzip2';
    case Zstd = 'zstd';

    public function isEnabled(): bool
    {
        return $this !== self::None;
    }

    /**
     * File extension appended to the archive for this algorithm.
     */
    public function extension(): string
    {
        return match ($this) {
            self::None => '',
            self::Gzip => 'gz',
            self::Bzip2 => 'bz2',
            self::Zstd => 'zst',
        };
    }
}
