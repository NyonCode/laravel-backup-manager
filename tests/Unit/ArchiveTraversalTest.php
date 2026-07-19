<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Archive\PosixTarArchiver;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;

/**
 * Adversarial tests for archive extraction — a crafted archive must never write
 * a file outside the destination directory (tar-slip / path traversal). Restore
 * is a destructive, fail-closed path: an entry that escapes must abort, never
 * clobber a file elsewhere on the host.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/bm-slip-'.uniqid();
    $this->extractDir = "{$this->root}/extract";
    mkdir($this->extractDir, 0777, true);
    $this->archive = "{$this->root}/evil.tar";
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
    exec('rm -f /tmp/bm-pwned.txt');
});

it('refuses a parent-traversal (../) entry instead of escaping the destination', function (): void {
    $archiver = new PosixTarArchiver;
    // A crafted archive whose entry name climbs out of the extraction root.
    $archiver->create([], ['../pwned.txt' => 'owned'], $this->archive);

    $escaped = dirname($this->extractDir).'/pwned.txt';

    expect(fn () => $archiver->extractAll($this->archive, $this->extractDir))
        ->toThrow(BackupManagerException::class);

    expect(file_exists($escaped))->toBeFalse();
});

it('refuses a deep parent-traversal entry', function (): void {
    $archiver = new PosixTarArchiver;
    $archiver->create([], ['../../../../../../tmp/bm-pwned.txt' => 'owned'], $this->archive);

    expect(fn () => $archiver->extractAll($this->archive, $this->extractDir))
        ->toThrow(BackupManagerException::class);

    expect(file_exists('/tmp/bm-pwned.txt'))->toBeFalse();
});

it('still extracts a legitimate nested entry', function (): void {
    $archiver = new PosixTarArchiver;
    $archiver->create([], ['files/var/www/app/config.php' => '<?php return [];'], $this->archive);

    $archiver->extractAll($this->archive, $this->extractDir);

    expect(file_get_contents("{$this->extractDir}/files/var/www/app/config.php"))
        ->toBe('<?php return [];');
});

it('normalises harmless . and interior .. that stay within the destination', function (): void {
    $archiver = new PosixTarArchiver;
    // deep/../keep.txt resolves to deep-sibling keep.txt — still inside root.
    $archiver->create([], ['files/deep/../keep.txt' => 'kept'], $this->archive);

    $archiver->extractAll($this->archive, $this->extractDir);

    expect(file_get_contents("{$this->extractDir}/files/keep.txt"))->toBe('kept');
});
