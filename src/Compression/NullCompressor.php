<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Compression;

use Nyoncode\BackupManager\Contracts\Compression\Compressor;

/**
 * A no-op compressor used when compression is disabled. Copies bytes verbatim.
 */
final class NullCompressor implements Compressor
{
    public function compress(string $source, string $destination): void
    {
        $this->copy($source, $destination);
    }

    public function decompress(string $source, string $destination): void
    {
        $this->copy($source, $destination);
    }

    public function extension(): string
    {
        return '';
    }

    private function copy(string $source, string $destination): void
    {
        if ($source !== $destination) {
            copy($source, $destination);
        }
    }
}
