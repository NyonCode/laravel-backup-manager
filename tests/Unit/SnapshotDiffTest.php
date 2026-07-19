<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;
use Nyoncode\BackupManager\ValueObjects\SnapshotDiff;

/**
 * Build a manifest file entry whose checksum is derived from $contents, so a
 * differing content string always yields a differing hash.
 */
function diffFile(string $path, string $contents, int $size = 0): ManifestFile
{
    return new ManifestFile(
        path: $path,
        size: $size === 0 ? strlen($contents) : $size,
        checksum: Checksum::forContents($contents, ChecksumAlgorithm::Sha256),
    );
}

it('treats every file as added when there is no base snapshot', function (): void {
    $current = [diffFile('files/a.txt', 'a'), diffFile('files/b.txt', 'b')];

    $diff = SnapshotDiff::between([], $current);

    expect($diff->added)->toHaveCount(2)
        ->and($diff->changed)->toBe([])
        ->and($diff->unchanged)->toBe([])
        ->and($diff->removed)->toBe([])
        ->and($diff->isUnchanged())->toBeFalse();
});

it('classifies added, changed, unchanged and removed files', function (): void {
    $base = [
        diffFile('files/keep.txt', 'same'),
        diffFile('files/edit.txt', 'old'),
        diffFile('files/gone.txt', 'bye'),
    ];

    $current = [
        diffFile('files/keep.txt', 'same'),   // unchanged
        diffFile('files/edit.txt', 'new'),    // changed
        diffFile('files/fresh.txt', 'hello'), // added
    ];

    $diff = SnapshotDiff::between($base, $current);

    expect(array_map(fn ($f) => $f->path, $diff->added))->toBe(['files/fresh.txt'])
        ->and(array_map(fn ($f) => $f->path, $diff->changed))->toBe(['files/edit.txt'])
        ->and(array_map(fn ($f) => $f->path, $diff->unchanged))->toBe(['files/keep.txt'])
        ->and(array_map(fn ($f) => $f->path, $diff->removed))->toBe(['files/gone.txt']);
});

it('reports nothing to store when the tree is identical', function (): void {
    $files = [diffFile('files/a.txt', 'a'), diffFile('files/b.txt', 'b')];

    $diff = SnapshotDiff::between($files, $files);

    expect($diff->isUnchanged())->toBeTrue()
        ->and($diff->newFiles())->toBe([])
        ->and($diff->newByteSize())->toBe(0)
        ->and($diff->unchanged)->toHaveCount(2);
});

it('collects added and changed files as the set that must be stored', function (): void {
    $base = [diffFile('files/edit.txt', 'old', 10)];
    $current = [
        diffFile('files/edit.txt', 'new', 10),
        diffFile('files/fresh.txt', 'hello', 5),
    ];

    $diff = SnapshotDiff::between($base, $current);

    expect(array_map(fn ($f) => $f->path, $diff->newFiles()))
        ->toEqualCanonicalizing(['files/edit.txt', 'files/fresh.txt'])
        ->and($diff->newByteSize())->toBe(15);
});

it('summarises the diff as counts', function (): void {
    $base = [diffFile('files/keep.txt', 'x'), diffFile('files/gone.txt', 'y')];
    $current = [diffFile('files/keep.txt', 'x'), diffFile('files/new.txt', 'z')];

    expect(SnapshotDiff::between($base, $current)->counts())->toBe([
        'added' => 1,
        'changed' => 0,
        'unchanged' => 1,
        'removed' => 1,
    ]);
});
