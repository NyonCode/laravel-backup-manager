<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Archive\Tar;

use Nyoncode\BackupManager\Exceptions\BackupManagerException;

/**
 * Encodes and decodes 512-byte tar headers (USTAR + GNU extensions).
 *
 * Two GNU extensions lift the classic ustar limits:
 *  - the size field uses base-256 encoding for files larger than 8 GiB, which
 *    the octal field cannot represent;
 *  - names longer than 100 bytes are carried in a preceding GNU "long link"
 *    entry (see {@see PosixTarArchiver}).
 */
final class TarHeader
{
    public const BLOCK = 512;

    public const TYPE_FILE = '0';

    public const TYPE_LONGNAME = 'L';

    public const GNU_LONGNAME = '././@LongLink';

    /**
     * The largest size representable by the 11-digit octal size field.
     */
    private const MAX_OCTAL_SIZE = 0o77777777777; // 8 GiB - 1

    /**
     * Build a 512-byte header block with a valid checksum.
     */
    public static function build(string $name, int $size, string $typeflag): string
    {
        $header = self::field($name, 100)
            .self::octal(0o644, 8)      // mode
            .self::octal(0, 8)          // uid
            .self::octal(0, 8)          // gid
            .self::encodeSize($size)    // size (octal or base-256)
            .self::octal(0, 12)         // mtime
            .str_repeat(' ', 8)         // checksum placeholder
            .$typeflag
            .self::field('', 100)       // linkname
            .'ustar'.chr(0)             // magic
            .'00'                       // version
            .self::field('', 32)        // uname
            .self::field('', 32)        // gname
            .self::octal(0, 8)          // devmajor
            .self::octal(0, 8)          // devminor
            .self::field('', 155)       // prefix
            .str_repeat(chr(0), 12);    // padding

        $header = str_pad($header, self::BLOCK, chr(0));

        return substr_replace($header, self::checksum($header), 148, 8);
    }

    /**
     * Number of 512-byte blocks needed to hold $size bytes.
     */
    public static function blocks(int $size): int
    {
        return (int) ceil($size / self::BLOCK);
    }

    /**
     * @return array{name: string, size: int, type: string}|null Null for an end-of-archive block.
     */
    public static function parse(string $block): ?array
    {
        if (trim($block, chr(0)) === '') {
            return null;
        }

        return [
            'name' => rtrim(substr($block, 0, 100), chr(0)),
            'size' => self::decodeSize(substr($block, 124, 12)),
            'type' => substr($block, 156, 1),
        ];
    }

    public static function encodeSize(int $size): string
    {
        if ($size <= self::MAX_OCTAL_SIZE) {
            return self::octal($size, 12);
        }

        // GNU base-256: high bit set on the first byte, big-endian binary after.
        $bytes = '';
        for ($i = 0; $i < 11; $i++) {
            $bytes = chr($size & 0xFF).$bytes;
            $size >>= 8;
        }

        return chr(0x80).$bytes;
    }

    public static function decodeSize(string $field): int
    {
        if ((ord($field[0]) & 0x80) !== 0) {
            $size = 0;
            for ($i = 1; $i < strlen($field); $i++) {
                // A crafted header can encode more than 63 bits; shifting on
                // would wrap to a negative or tiny size and desynchronise the
                // reader. No real entry is that large.
                if ($size > (PHP_INT_MAX >> 8)) {
                    throw new BackupManagerException('Archive entry size overflows; the archive is corrupt or crafted.');
                }

                $size = ($size << 8) | ord($field[$i]);
            }

            return $size;
        }

        $trimmed = trim($field, " \0");

        return $trimmed === '' ? 0 : (int) octdec($trimmed);
    }

    /**
     * Content padded with NUL bytes up to a 512-byte boundary.
     */
    public static function pad(int $size): string
    {
        $remainder = $size % self::BLOCK;

        return $remainder === 0 ? '' : str_repeat(chr(0), self::BLOCK - $remainder);
    }

    private static function checksum(string $header): string
    {
        $sum = 0;
        for ($i = 0, $length = strlen($header); $i < $length; $i++) {
            $sum += ord($header[$i]);
        }

        return sprintf('%06o', $sum).chr(0).' ';
    }

    private static function octal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT).chr(0);
    }

    private static function field(string $value, int $length): string
    {
        return str_pad(substr($value, 0, $length), $length, chr(0));
    }
}
