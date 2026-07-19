<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Archive\PosixTarArchiver;

function tarWorkdir(): string
{
    $dir = sys_get_temp_dir().'/bm-ptar-'.uniqid();
    mkdir("{$dir}/src", 0777, true);

    return $dir;
}

it('round-trips files and strings, reading entries back', function (): void {
    $dir = tarWorkdir();
    file_put_contents("{$dir}/src/a.txt", 'alpha');
    file_put_contents("{$dir}/src/dump.sql", str_repeat('SQL', 100_000));

    $archiver = new PosixTarArchiver;
    $tar = "{$dir}/backup.tar";
    $archiver->create(
        fileEntries: ['files/a.txt' => "{$dir}/src/a.txt", 'databases/dump.sql' => "{$dir}/src/dump.sql"],
        stringEntries: ['manifest.json' => '{"schema_version":"1.0"}'],
        destination: $tar,
    );

    expect($archiver->has($tar, 'manifest.json'))->toBeTrue()
        ->and($archiver->has($tar, 'missing'))->toBeFalse()
        ->and($archiver->read($tar, 'manifest.json'))->toBe('{"schema_version":"1.0"}')
        ->and($archiver->read($tar, 'files/a.txt'))->toBe('alpha')
        ->and(strlen($archiver->read($tar, 'databases/dump.sql')))->toBe(300_000);
});

it('extracts everything to disk with byte-for-byte fidelity', function (): void {
    $dir = tarWorkdir();
    $payload = random_bytes(2_500_007); // spans multiple chunks, non-block-aligned
    file_put_contents("{$dir}/src/binary.bin", $payload);

    $archiver = new PosixTarArchiver;
    $tar = "{$dir}/backup.tar";
    $archiver->create(['files/binary.bin' => "{$dir}/src/binary.bin"], [], $tar);

    $archiver->extractAll($tar, "{$dir}/out");

    expect(hash_file('sha256', "{$dir}/out/files/binary.bin"))->toBe(hash('sha256', $payload));
});

it('extracts only selected path prefixes', function (): void {
    $dir = tarWorkdir();
    file_put_contents("{$dir}/src/a.txt", 'alpha');
    file_put_contents("{$dir}/src/dump.sql", 'sql');

    $archiver = new PosixTarArchiver;
    $tar = "{$dir}/backup.tar";
    $archiver->create(['files/a.txt' => "{$dir}/src/a.txt", 'databases/dump.sql' => "{$dir}/src/dump.sql"], [], $tar);

    $archiver->extractPaths($tar, ['databases/'], "{$dir}/out");

    expect(is_file("{$dir}/out/databases/dump.sql"))->toBeTrue()
        ->and(is_file("{$dir}/out/files/a.txt"))->toBeFalse();
});

it('handles names far longer than the 100-byte ustar limit (GNU long name)', function (): void {
    $dir = tarWorkdir();
    // 250-char archive path, well beyond ustar's 100-byte name field.
    $longName = 'files/'.str_repeat('deep-directory-segment/', 10).'file.txt';
    expect(strlen($longName))->toBeGreaterThan(100);

    file_put_contents("{$dir}/src/x", 'long-name-content');

    $archiver = new PosixTarArchiver;
    $tar = "{$dir}/backup.tar";
    $archiver->create([$longName => "{$dir}/src/x"], [], $tar);

    expect($archiver->read($tar, $longName))->toBe('long-name-content')
        ->and($archiver->has($tar, $longName))->toBeTrue();

    $archiver->extractAll($tar, "{$dir}/out");
    expect(file_get_contents("{$dir}/out/{$longName}"))->toBe('long-name-content');
});

it('produces a tar readable by the system tar binary', function (): void {
    if (! is_executable('/usr/bin/tar') && ! is_executable('/bin/tar')) {
        $this->markTestSkipped('system tar not available');
    }

    $dir = tarWorkdir();
    file_put_contents("{$dir}/src/a.txt", 'interop');
    $longName = 'files/'.str_repeat('seg/', 30).'z.txt';

    $archiver = new PosixTarArchiver;
    $tar = "{$dir}/backup.tar";
    $archiver->create(['files/a.txt' => "{$dir}/src/a.txt", $longName => "{$dir}/src/a.txt"], [], $tar);

    $output = shell_exec('tar -tf '.escapeshellarg($tar).' 2>&1');

    expect($output)->toContain('files/a.txt')
        ->and($output)->toContain('z.txt');
});
