<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Managers\CompressorManager;
use Nyoncode\BackupManager\Support\BinaryLocator;

function edgeCompressor(CompressionAlgorithm $algorithm)
{
    return (new CompressorManager(new BinaryLocator, level: 9))->for($algorithm);
}

$algorithms = [
    'none' => [CompressionAlgorithm::None],
    'gzip' => [CompressionAlgorithm::Gzip],
    'bzip2' => [CompressionAlgorithm::Bzip2],
    'zstd' => [CompressionAlgorithm::Zstd],
];

dataset('all algorithms', $algorithms);

dataset('sizes', [
    'empty' => [0],
    'tiny' => [3],
    'chunk minus one' => [1_048_575],
    'exactly one chunk' => [1_048_576],
    'chunk plus one' => [1_048_577],
    'large' => [3_000_000],
]);

it('round-trips losslessly at every size and algorithm', function (CompressionAlgorithm $algorithm, int $size) {
    if ($algorithm === CompressionAlgorithm::Zstd && ! (new BinaryLocator)->has('zstd')) {
        $this->markTestSkipped('zstd binary not available');
    }

    $dir = sys_get_temp_dir().'/bm-cmp-'.uniqid();
    mkdir($dir);
    // Mix of compressible and incompressible content.
    $data = $size === 0 ? '' : (str_repeat('AB', intdiv($size, 4)).random_bytes($size - 2 * intdiv($size, 4)));
    file_put_contents("$dir/plain", $data);

    $compressor = edgeCompressor($algorithm);
    $compressor->compress("$dir/plain", "$dir/cmp");
    $compressor->decompress("$dir/cmp", "$dir/out");

    expect(hash_file('sha256', "$dir/out"))->toBe(hash_file('sha256', "$dir/plain"));
})->with('all algorithms')->with('sizes');

it('actually shrinks highly compressible data', function (CompressionAlgorithm $algorithm) {
    if ($algorithm === CompressionAlgorithm::Zstd && ! (new BinaryLocator)->has('zstd')) {
        $this->markTestSkipped('zstd binary not available');
    }

    $dir = sys_get_temp_dir().'/bm-cmp-'.uniqid();
    mkdir($dir);
    file_put_contents("$dir/plain", str_repeat('the quick brown fox ', 100_000));

    $compressor = edgeCompressor($algorithm);
    $compressor->compress("$dir/plain", "$dir/cmp");

    expect(filesize("$dir/cmp"))->toBeLessThan(filesize("$dir/plain"));
})->with([
    'gzip' => [CompressionAlgorithm::Gzip],
    'bzip2' => [CompressionAlgorithm::Bzip2],
    'zstd' => [CompressionAlgorithm::Zstd],
]);
