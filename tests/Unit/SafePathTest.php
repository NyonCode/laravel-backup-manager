<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Support\SafePath;

/**
 * The traversal guard shared by every restore write path (archive extraction
 * and snapshot materialisation). It is the single choke point that keeps a
 * hostile backup from writing outside its destination.
 */
it('resolves a plain nested entry inside the root', function (): void {
    expect(SafePath::resolveWithin('/var/extract', 'files/app/config.php'))
        ->toBe('/var/extract/files/app/config.php');
});

it('collapses interior . and .. that stay inside the root', function (): void {
    expect(SafePath::resolveWithin('/var/extract', 'files/deep/../keep.txt'))
        ->toBe('/var/extract/files/keep.txt');
});

it('tolerates a trailing slash on the root', function (): void {
    expect(SafePath::resolveWithin('/var/extract/', 'a.txt'))->toBe('/var/extract/a.txt');
});

it('refuses entries that escape via ..', function (string $name): void {
    expect(fn () => SafePath::resolveWithin('/var/extract', $name))
        ->toThrow(BackupManagerException::class);
})->with([
    'single parent' => '../pwned.txt',
    'nested parent' => 'files/../../pwned.txt',
    'deep parent' => '../../../../../../etc/cron.d/evil',
    'parent then descend' => '../extract-evil/x',
]);

it('refuses a sibling directory that shares the root prefix', function (): void {
    // /var/extract-evil must not be treated as inside /var/extract.
    expect(fn () => SafePath::resolveWithin('/var/extract', '../extract-evil/x.txt'))
        ->toThrow(BackupManagerException::class);
});

it('allows an entry that resolves exactly to the root', function (): void {
    expect(SafePath::resolveWithin('/var/extract', 'files/..'))->toBe('/var/extract');
});
