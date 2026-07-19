<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\ComposerAuthFile;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bm-auth-'.uniqid().'.json';
});

afterEach(function (): void {
    @unlink($this->path);
});

it('creates the file with the http-basic credentials when none exists', function (): void {
    expect(is_file($this->path))->toBeFalse();

    (new ComposerAuthFile($this->path))->putHttpBasic('repo.example.test', 'token', 'SECRET');

    $decoded = json_decode((string) file_get_contents($this->path), true);

    expect($decoded)->toBe([
        'http-basic' => [
            'repo.example.test' => ['username' => 'token', 'password' => 'SECRET'],
        ],
    ]);
});

it('writes valid, pretty-printed JSON with a trailing newline', function (): void {
    (new ComposerAuthFile($this->path))->putHttpBasic('repo.example.test', 'token', 'SECRET');

    $raw = (string) file_get_contents($this->path);

    expect(json_decode($raw, true))->not->toBeNull()      // parseable
        ->and($raw)->toContain("\n    ")                   // pretty printed
        ->and(str_ends_with($raw, "\n"))->toBeTrue();
});

it('merges a new host without clobbering an existing one', function (): void {
    file_put_contents($this->path, json_encode([
        'http-basic' => [
            'other.example.test' => ['username' => 'u', 'password' => 'p'],
        ],
    ]));

    (new ComposerAuthFile($this->path))->putHttpBasic('repo.example.test', 'token', 'SECRET');

    $decoded = json_decode((string) file_get_contents($this->path), true);

    expect($decoded['http-basic'])->toHaveKeys(['other.example.test', 'repo.example.test'])
        ->and($decoded['http-basic']['other.example.test'])->toBe(['username' => 'u', 'password' => 'p']);
});

it('overwrites the credentials for the same host', function (): void {
    $file = new ComposerAuthFile($this->path);
    $file->putHttpBasic('repo.example.test', 'token', 'OLD');
    $file->putHttpBasic('repo.example.test', 'token', 'NEW');

    $decoded = json_decode((string) file_get_contents($this->path), true);

    expect($decoded['http-basic']['repo.example.test']['password'])->toBe('NEW')
        ->and($decoded['http-basic'])->toHaveCount(1);
});

it('preserves unrelated top-level keys', function (): void {
    file_put_contents($this->path, json_encode([
        'github-oauth' => ['github.com' => 'gho_xxx'],
    ]));

    (new ComposerAuthFile($this->path))->putHttpBasic('repo.example.test', 'token', 'SECRET');

    $decoded = json_decode((string) file_get_contents($this->path), true);

    expect($decoded['github-oauth'])->toBe(['github.com' => 'gho_xxx'])
        ->and($decoded['http-basic']['repo.example.test']['password'])->toBe('SECRET');
});

it('starts fresh when the existing file is malformed', function (): void {
    file_put_contents($this->path, '{ this is not valid json');

    (new ComposerAuthFile($this->path))->putHttpBasic('repo.example.test', 'token', 'SECRET');

    $decoded = json_decode((string) file_get_contents($this->path), true);

    expect($decoded)->toBe([
        'http-basic' => [
            'repo.example.test' => ['username' => 'token', 'password' => 'SECRET'],
        ],
    ]);
});

it('restricts the credentials file to the owner', function (): void {
    (new ComposerAuthFile($this->path))->putHttpBasic('repo.example.test', 'token', 'SECRET');

    // Permission bits are meaningful on POSIX only.
    expect(substr(sprintf('%o', fileperms($this->path)), -3))->toBe('600');
})->skipOnWindows();
