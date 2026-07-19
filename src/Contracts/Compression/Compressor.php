<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Compression;

/**
 * Capability: compress and decompress a file on disk.
 *
 * Implementations stream their work to keep memory usage flat regardless of
 * archive size.
 */
interface Compressor
{
    public function compress(string $source, string $destination): void;

    public function decompress(string $source, string $destination): void;

    /**
     * The file extension this compressor appends (without a leading dot).
     */
    public function extension(): string;
}
