<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Compression;

use Nyoncode\BackupManager\Contracts\Compression\Compressor;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;

/**
 * Streaming bzip2 compressor built on PHP's native bz2 functions.
 */
final class Bzip2Compressor implements Compressor
{
    private const CHUNK = 1_048_576; // 1 MiB

    public function compress(string $source, string $destination): void
    {
        $in = $this->open($source, 'rb');
        $out = bzopen($destination, 'w');

        if ($out === false) {
            fclose($in);
            throw new BackupManagerException("Unable to open [{$destination}] for bzip2 compression.");
        }

        while (! feof($in)) {
            bzwrite($out, (string) fread($in, self::CHUNK));
        }

        fclose($in);
        bzclose($out);
    }

    public function decompress(string $source, string $destination): void
    {
        $in = bzopen($source, 'r');

        if ($in === false) {
            throw new BackupManagerException("Unable to open [{$source}] for bzip2 decompression.");
        }

        $out = $this->open($destination, 'wb');

        while (($chunk = bzread($in, self::CHUNK)) !== false && $chunk !== '') {
            fwrite($out, $chunk);
        }

        bzclose($in);
        fclose($out);
    }

    public function extension(): string
    {
        return 'bz2';
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
