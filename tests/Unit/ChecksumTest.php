<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\ValueObjects\Checksum;

it('computes a sha-256 checksum of a string', function (): void {
    $checksum = Checksum::forContents('hello world');

    expect($checksum->algorithm)->toBe(ChecksumAlgorithm::Sha256)
        ->and($checksum->digest)->toBe(hash('sha256', 'hello world'));
});

it('computes a checksum of a file', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'bm');
    file_put_contents($path, 'backup contents');

    $checksum = Checksum::forFile($path);

    expect($checksum->matchesFile($path))->toBeTrue()
        ->and($checksum->digest)->toBe(hash_file('sha256', $path));

    unlink($path);
});

it('compares checksums in constant time', function (): void {
    $a = Checksum::forContents('a');
    $b = Checksum::forContents('a');
    $c = Checksum::forContents('b');

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});

it('round-trips through its array representation', function (): void {
    $checksum = Checksum::forContents('data', ChecksumAlgorithm::Sha512);

    expect(Checksum::fromArray($checksum->toArray()))->toEqual($checksum);
});

it('stringifies as algorithm:digest', function (): void {
    $checksum = Checksum::forContents('x');

    expect((string) $checksum)->toBe('sha256:'.hash('sha256', 'x'));
});
