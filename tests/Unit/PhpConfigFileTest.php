<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Exceptions\ConfigWriteException;
use Nyoncode\BackupManager\Support\PhpConfigFile;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bm-config-'.uniqid().'.php';
    file_put_contents($this->path, <<<'PHP_FIXTURE'
    <?php

    return [

        // The profile used when none is named.
        'default' => env('BACKUP_PROFILE', 'default'),

        'profiles' => [
            'default' => [
                'type' => 'full', // what to include
                'destinations' => [
                    'local',
                ],
                'files' => [
                    'include' => ['/srv/app'],
                ],
            ],
        ],

        'retention' => [
            'simple' => [
                'keep_latest' => 10,
            ],
            'max_age_days' => 365,
        ],

        'empty' => [],
    ];
    PHP_FIXTURE);
});

afterEach(function (): void {
    @unlink($this->path);
});

function fixtureValue(string $path, string $key): mixed
{
    $config = require $path;

    return data_get($config, $key);
}

it('replaces a scalar without touching the rest of the file', function (): void {
    $file = new PhpConfigFile($this->path);

    $edit = $file->edit(['retention.simple.keep_latest' => 30]);
    $file->write($edit['source']);

    expect($edit['changes'])->toBe([['key' => 'retention.simple.keep_latest', 'from' => '10', 'to' => '30']])
        ->and(fixtureValue($this->path, 'retention.simple.keep_latest'))->toBe(30)
        ->and((string) file_get_contents($this->path))
        ->toContain('// The profile used when none is named.')
        ->toContain("'default' => env('BACKUP_PROFILE', 'default')")
        ->toContain('// what to include');
});

it('replaces an array value', function (): void {
    $file = new PhpConfigFile($this->path);
    $file->write($file->edit(['profiles.default.destinations' => ['local', 's3']])['source']);

    expect(fixtureValue($this->path, 'profiles.default.destinations'))->toBe(['local', 's3']);
});

it('creates keys, and any missing level between them', function (): void {
    $file = new PhpConfigFile($this->path);

    $edit = $file->edit([
        'profiles.hourly.type' => 'database',
        'profiles.hourly.databases.connections' => ['mysql'],
        'schedule.enabled' => true,
    ]);
    $file->write($edit['source']);

    expect(fixtureValue($this->path, 'profiles.hourly'))
        ->toBe(['type' => 'database', 'databases' => ['connections' => ['mysql']]])
        ->and(fixtureValue($this->path, 'schedule.enabled'))->toBeTrue()
        ->and(fixtureValue($this->path, 'profiles.default.type'))->toBe('full');
});

it('fills an empty array literal', function (): void {
    $file = new PhpConfigFile($this->path);
    $file->write($file->edit(['empty.nested' => 'value'])['source']);

    expect(fixtureValue($this->path, 'empty'))->toBe(['nested' => 'value']);
});

it('leaves the file alone when the value is already there', function (): void {
    $before = (string) file_get_contents($this->path);

    $edit = (new PhpConfigFile($this->path))->edit(['retention.max_age_days' => 365]);

    expect($edit['changes'])->toBe([])
        ->and($edit['source'])->toBe($before);
});

it('does not write during an edit', function (): void {
    $before = (string) file_get_contents($this->path);

    (new PhpConfigFile($this->path))->edit(['retention.simple.keep_latest' => 99]);

    expect((string) file_get_contents($this->path))->toBe($before);
});

it('keeps a copy of the previous file when asked', function (): void {
    $directory = sys_get_temp_dir().'/bm-config-backups-'.uniqid();
    $file = new PhpConfigFile($this->path);
    $before = (string) file_get_contents($this->path);

    $backup = $file->write($file->edit(['retention.simple.keep_latest' => 5])['source'], $directory);

    expect($backup)->toBeString()
        ->and((string) file_get_contents((string) $backup))->toBe($before);

    array_map('unlink', glob($directory.'/*') ?: []);
    @rmdir($directory);
});

it('refuses a path whose parent is not an array', function (): void {
    expect(fn (): array => (new PhpConfigFile($this->path))->edit(['retention.max_age_days.nope' => 1]))
        ->toThrow(ConfigWriteException::class);
});

it('refuses to edit a file it cannot read', function (): void {
    expect(fn (): array => (new PhpConfigFile(sys_get_temp_dir().'/bm-absent-'.uniqid().'.php'))->edit(['a' => 1]))
        ->toThrow(ConfigWriteException::class);
});

it('reports the source of a value', function (): void {
    $file = new PhpConfigFile($this->path);

    expect($file->sourceOf('default'))->toBe("env('BACKUP_PROFILE', 'default')")
        ->and($file->sourceOf('retention.simple.keep_latest'))->toBe('10')
        ->and($file->sourceOf('nope.nope'))->toBeNull();
});
