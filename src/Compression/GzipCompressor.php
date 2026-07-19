<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Compression;

use Nyoncode\BackupManager\Contracts\Compression\Compressor;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;

/**
 * Streaming gzip compressor built on PHP's native zlib functions.
 *
 * Requires no external binary and keeps memory flat by copying in chunks.
 */
final class GzipCompressor implements Compressor
{
    private const CHUNK = 1_048_576; // 1 MiB

    public function __construct(
        private readonly int $level = 6,
    ) {}

    public function compress(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $out = gzopen($destination, 'wb'.$this->clampLevel());

        if ($out === false) {
            fclose($in);
            throw new BackupManagerException("Unable to open [{$destination}] for gzip compression.");
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, self::CHUNK));
        }

        fclose($in);
        gzclose($out);
    }

    public function decompress(string $source, string $destination): void
    {
        $in = gzopen($source, 'rb');

        if ($in === false) {
            throw new BackupManagerException("Unable to open [{$source}] for gzip decompression.");
        }

        $out = $this->open($destination, 'wb');

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, self::CHUNK));
        }

        gzclose($in);
        fclose($out);
    }

    public function extension(): string
    {
        return 'gz';
    }

    private function clampLevel(): int
    {
        return max(1, min(9, $this->level));
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new BackupManagerException("Unable to open [{$path}].");
        }

        return $handle;
    }
}
