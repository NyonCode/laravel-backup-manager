<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Managers\CompressorManager;
use Nyoncode\BackupManager\Support\BinaryLocator;

function compressor(CompressionAlgorithm $algorithm)
{
    return (new CompressorManager(new BinaryLocator))->for($algorithm);
}

dataset('lossless algorithms', [
    'none' => [CompressionAlgorithm::None],
    'gzip' => [CompressionAlgorithm::Gzip],
    'bzip2' => [CompressionAlgorithm::Bzip2],
]);

it('compresses and decompresses without data loss', function (CompressionAlgorithm $algorithm): void {
    $dir = sys_get_temp_dir().'/bm-'.uniqid();
    mkdir($dir);
    $original = "{$dir}/original.bin";
    file_put_contents($original, random_bytes(500_000).str_repeat('compressible ', 10_000));

    $compressor = compressor($algorithm);
    $compressed = "{$dir}/data.".($compressor->extension() ?: 'bin');
    $restored = "{$dir}/restored.bin";

    $compressor->compress($original, $compressed);
    $compressor->decompress($compressed, $restored);

    expect(hash_file('sha256', $restored))->toBe(hash_file('sha256', $original));
})->with('lossless algorithms');
