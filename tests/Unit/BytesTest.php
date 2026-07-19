<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\Bytes;

it('renders zero and non-positive counts as 0 B', function (int $bytes): void {
    expect(Bytes::human($bytes))->toBe('0 B');
})->with([0, -1, -1024]);

it('formats byte counts across units', function (int $bytes, string $expected): void {
    expect(Bytes::human($bytes))->toBe($expected);
})->with([
    'bytes' => [512, '512 B'],
    'one kilobyte' => [1024, '1 KB'],
    'one and a half kilobytes' => [1536, '1.5 KB'],
    'one megabyte' => [1024 ** 2, '1 MB'],
    'one gigabyte' => [1024 ** 3, '1 GB'],
    'one terabyte' => [1024 ** 4, '1 TB'],
    'one petabyte' => [1024 ** 5, '1 PB'],
]);

it('caps at the largest known unit (PB)', function (): void {
    // An exabyte has no unit in the table, so it is expressed in petabytes.
    expect(Bytes::human(1024 ** 6))->toBe('1024 PB');
});

it('honours the precision argument', function (): void {
    expect(Bytes::human(1536, 0))->toBe('2 KB')
        ->and(Bytes::human(1536, 1))->toBe('1.5 KB')
        ->and(Bytes::human(1234567, 3))->toBe('1.177 MB');
});
