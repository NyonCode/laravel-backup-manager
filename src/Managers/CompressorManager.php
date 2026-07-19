<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Managers;

use Nyoncode\BackupManager\Compression\Bzip2Compressor;
use Nyoncode\BackupManager\Compression\GzipCompressor;
use Nyoncode\BackupManager\Compression\NullCompressor;
use Nyoncode\BackupManager\Compression\ZstdCompressor;
use Nyoncode\BackupManager\Contracts\Compression\Compressor;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Support\BinaryLocator;

/**
 * Resolves a compressor implementation for a compression algorithm.
 */
final class CompressorManager
{
    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly int $level = 6,
        private readonly ?int $timeout = null,
    ) {}

    public function for(CompressionAlgorithm $algorithm): Compressor
    {
        return match ($algorithm) {
            CompressionAlgorithm::None => new NullCompressor,
            CompressionAlgorithm::Gzip => new GzipCompressor($this->level),
            CompressionAlgorithm::Bzip2 => new Bzip2Compressor,
            CompressionAlgorithm::Zstd => new ZstdCompressor($this->binaries, $this->level, $this->timeout),
        };
    }
}
