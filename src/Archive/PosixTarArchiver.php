<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Archive;

use Nyoncode\BackupManager\Archive\Tar\TarHeader;
use Nyoncode\BackupManager\Contracts\Archive\Archiver;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Support\SafePath;

/**
 * A self-contained, streaming TAR archiver (USTAR + GNU extensions).
 *
 * Unlike PharData it has no 8 GiB per-file limit (GNU base-256 sizes) and no
 * 100-byte name limit (GNU long names), and it streams both reading and writing
 * so memory stays flat regardless of archive or file size.
 */
final class PosixTarArchiver implements Archiver
{
    private const CHUNK = 1_048_576; // 1 MiB

    public function create(array $fileEntries, array $stringEntries, string $destination): void
    {
        $out = $this->open($destination, 'wb');

        foreach ($fileEntries as $entry => $source) {
            $this->writeFileEntry($out, $entry, $source);
        }

        foreach ($stringEntries as $entry => $contents) {
            $this->writeStringEntry($out, $entry, $contents);
        }

        // Two zero blocks terminate the archive.
        fwrite($out, str_repeat(chr(0), TarHeader::BLOCK * 2));
        fclose($out);
    }

    public function extractAll(string $archive, string $destination): void
    {
        $this->extract($archive, $destination, null);
    }

    public function extractPaths(string $archive, array $prefixes, string $destination): void
    {
        $this->extract($archive, $destination, $prefixes);
    }

    public function read(string $archive, string $entry): string
    {
        $in = $this->open($archive, 'rb');

        try {
            while (($header = $this->nextEntry($in)) !== null) {
                if ($header['name'] === $entry && $this->isFile($header['type'])) {
                    return $this->streamContent($in, $header['size'], null, capture: true);
                }

                $this->streamContent($in, $header['size'], null);
            }
        } finally {
            fclose($in);
        }

        throw new BackupManagerException("Archive entry [{$entry}] not found in [{$archive}].");
    }

    public function has(string $archive, string $entry): bool
    {
        $in = $this->open($archive, 'rb');

        try {
            while (($header = $this->nextEntry($in)) !== null) {
                if ($header['name'] === $entry) {
                    return true;
                }

                $this->streamContent($in, $header['size'], null);
            }
        } finally {
            fclose($in);
        }

        return false;
    }

    /**
     * @param  list<string>|null  $prefixes  Null extracts everything.
     */
    private function extract(string $archive, string $destination, ?array $prefixes): void
    {
        $in = $this->open($archive, 'rb');

        try {
            while (($header = $this->nextEntry($in)) !== null) {
                if (! $this->isFile($header['type']) || ! $this->matches($header['name'], $prefixes)) {
                    $this->streamContent($in, $header['size'], null);

                    continue;
                }

                $target = SafePath::resolveWithin($destination, $header['name']);
                $this->ensureDirectory(dirname($target));

                $handle = $this->open($target, 'wb');
                $this->streamContent($in, $header['size'], $handle);
                fclose($handle);
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * @param  resource  $out
     */
    private function writeFileEntry($out, string $name, string $source): void
    {
        $size = (int) (filesize($source) ?: 0);
        $this->writeHeader($out, $name, $size);

        $in = $this->open($source, 'rb');
        while (! feof($in)) {
            $chunk = (string) fread($in, self::CHUNK);
            if ($chunk !== '') {
                fwrite($out, $chunk);
            }
        }
        fclose($in);

        fwrite($out, TarHeader::pad($size));
    }

    /**
     * @param  resource  $out
     */
    private function writeStringEntry($out, string $name, string $contents): void
    {
        $size = strlen($contents);
        $this->writeHeader($out, $name, $size);
        fwrite($out, $contents);
        fwrite($out, TarHeader::pad($size));
    }

    /**
     * @param  resource  $out
     */
    private function writeHeader($out, string $name, int $size): void
    {
        // Names longer than 100 bytes are carried in a preceding GNU long name.
        if (strlen($name) > 100) {
            $longName = $name.chr(0);
            fwrite($out, TarHeader::build(TarHeader::GNU_LONGNAME, strlen($longName), TarHeader::TYPE_LONGNAME));
            fwrite($out, $longName);
            fwrite($out, TarHeader::pad(strlen($longName)));
        }

        fwrite($out, TarHeader::build($name, $size, TarHeader::TYPE_FILE));
    }

    /**
     * Read the next real entry header, resolving any GNU long-name prefix.
     *
     * @param  resource  $in
     * @return array{name: string, size: int, type: string}|null
     */
    private function nextEntry($in): ?array
    {
        $pendingName = null;

        while (true) {
            $block = (string) fread($in, TarHeader::BLOCK);

            if (strlen($block) < TarHeader::BLOCK) {
                return null;
            }

            $header = TarHeader::parse($block);

            if ($header === null) {
                return null;
            }

            if ($header['type'] === TarHeader::TYPE_LONGNAME) {
                $pendingName = rtrim($this->streamContent($in, $header['size'], null, capture: true), chr(0));

                continue;
            }

            return [
                'name' => $pendingName ?? $header['name'],
                'size' => $header['size'],
                'type' => $header['type'],
            ];
        }
    }

    /**
     * Consume an entry's content (size bytes + padding), optionally streaming or
     * capturing it.
     *
     * @param  resource  $in
     * @param  resource|null  $out
     */
    private function streamContent($in, int $size, $out, bool $capture = false): string
    {
        $captured = '';
        $remaining = $size;

        while ($remaining > 0) {
            $chunk = (string) fread($in, (int) min(self::CHUNK, $remaining));

            if ($chunk === '') {
                break;
            }

            $remaining -= strlen($chunk);

            if ($capture) {
                $captured .= $chunk;
            } elseif ($out !== null) {
                fwrite($out, $chunk);
            }
        }

        $padding = TarHeader::pad($size);
        if ($padding !== '') {
            fread($in, strlen($padding));
        }

        return $captured;
    }

    /**
     * @param  list<string>|null  $prefixes
     */
    private function matches(string $name, ?array $prefixes): bool
    {
        if ($prefixes === null) {
            return true;
        }

        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isFile(string $type): bool
    {
        return $type === TarHeader::TYPE_FILE || $type === chr(0);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new BackupManagerException("Unable to create directory [{$directory}] during extraction.");
        }
    }

    /**
     * @return resource
     */
    private function open(string $path, string $mode)
    {
        if ($mode[0] === 'w') {
            $this->ensureDirectory(dirname($path));
        }

        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new BackupManagerException("Unable to open [{$path}].");
        }

        return $handle;
    }
}
