<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Archive\Tar\TarHeader;

it('encodes small sizes as octal and round-trips them', function (int $size): void {
    $field = TarHeader::encodeSize($size);

    expect(strlen($field))->toBe(12)
        ->and(ord($field[0]) & 0x80)->toBe(0) // not base-256
        ->and(TarHeader::decodeSize($field))->toBe($size);
})->with([0, 1, 512, 1_048_576, 8_589_934_591]); // up to 8 GiB - 1

it('encodes sizes above 8 GiB using GNU base-256 and round-trips them', function (int $size): void {
    $field = TarHeader::encodeSize($size);

    expect(strlen($field))->toBe(12)
        ->and(ord($field[0]) & 0x80)->toBe(0x80) // base-256 marker
        ->and(TarHeader::decodeSize($field))->toBe($size);
})->with([
    '8 GiB' => [8_589_934_592],
    '10 GiB' => [10_737_418_240],
    '1 TiB' => [1_099_511_627_776],
    '20 TiB' => [21_990_232_555_520],
]);

it('builds a 512-byte header with a valid checksum', function (): void {
    $header = TarHeader::build('files/app.php', 1234, TarHeader::TYPE_FILE);

    expect(strlen($header))->toBe(512);

    // Recompute the checksum with the field blanked and compare.
    $stored = (int) octdec(trim(substr($header, 148, 8), " \0"));
    $blanked = substr_replace($header, str_repeat(' ', 8), 148, 8);
    $sum = 0;
    for ($i = 0; $i < 512; $i++) {
        $sum += ord($blanked[$i]);
    }

    expect($stored)->toBe($sum);

    $parsed = TarHeader::parse($header);
    expect($parsed['name'])->toBe('files/app.php')
        ->and($parsed['size'])->toBe(1234)
        ->and($parsed['type'])->toBe(TarHeader::TYPE_FILE);
});

it('treats an all-zero block as end-of-archive', function (): void {
    expect(TarHeader::parse(str_repeat(chr(0), 512)))->toBeNull();
});
