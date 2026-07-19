<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\ArchivePath;

it('maps POSIX absolute paths to and from archive entries', function (): void {
    $archive = ArchivePath::fromAbsolute('/var/www/app/Models/User.php');

    expect($archive)->toBe('files/var/www/app/Models/User.php')
        ->and(ArchivePath::toAbsolute($archive))->toBe('/var/www/app/Models/User.php');
});

it('maps Windows drive-letter paths to and from archive entries', function (): void {
    $archive = ArchivePath::fromAbsolute('C:\\Users\\acme\\app\\config.php');

    expect($archive)->toBe('files/C:/Users/acme/app/config.php')
        // reconstructed as a drive path, NOT with a bogus leading slash
        ->and(ArchivePath::toAbsolute($archive))->toBe('C:/Users/acme/app/config.php');
});

it('recognises file entries', function (): void {
    expect(ArchivePath::isFileEntry('files/x'))->toBeTrue()
        ->and(ArchivePath::isFileEntry('databases/dump.sql'))->toBeFalse();
});
