<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Support\ConfigReference;
use Nyoncode\BackupManager\ValueObjects\ConfigOption;

it('matches a concrete profile path against the wildcard key', function (): void {
    $option = new ConfigOption(key: 'profiles.*.type', type: 'string', description: '');

    expect($option->matches('profiles.default.type'))->toBeTrue()
        ->and($option->matches('profiles.hourly.type'))->toBeTrue()
        ->and($option->matches('profiles.hourly.files.type'))->toBeFalse()
        ->and($option->matches('profiles.type'))->toBeFalse();
});

it('accepts only values of the declared type', function (): void {
    $integer = new ConfigOption(key: 'retention.simple.keep_latest', type: 'integer', description: '');
    $nullable = new ConfigOption(key: 'retention.max_age_days', type: 'integer?', description: '');
    $list = new ConfigOption(key: 'profiles.*.destinations', type: 'string[]', description: '');

    expect($integer->accepts(10))->toBeTrue()
        ->and($integer->accepts('10'))->toBeFalse()
        ->and($integer->accepts(null))->toBeFalse()
        ->and($nullable->accepts(null))->toBeTrue()
        ->and($list->accepts(['s3']))->toBeTrue()
        ->and($list->accepts(['s3', 7]))->toBeFalse()
        ->and($list->accepts(['a' => 's3']))->toBeFalse();
});

it('accepts only allowed values for an enumeration', function (): void {
    $option = new ConfigOption(key: 'compression.algorithm', type: 'string', description: '', allowed: ['none', 'gzip']);

    expect($option->accepts('gzip'))->toBeTrue()
        ->and($option->accepts('zstd'))->toBeFalse();
});

it('never exposes the default of a secret', function (): void {
    $option = new ConfigOption(key: 'encryption.key', type: 'string?', description: '', default: 'base64:xxx', secret: true);

    expect($option->toArray())->not->toHaveKey('default')
        ->and($option->toArray()['secret'])->toBeTrue();
});

it('suggests the nearest key for a typo', function (): void {
    $reference = new ConfigReference;

    expect($reference->suggest('retention.simple.keep_latests'))->toBe('retention.simple.keep_latest')
        ->and($reference->suggest('profiles.default.destination'))->toBe('profiles.*.destinations');
});

it('finds the option behind an environment variable', function (): void {
    $reference = new ConfigReference;

    expect($reference->findByEnv('BACKUP_ENCRYPTION_KEY')?->key)->toBe('encryption.key')
        ->and($reference->findByEnv('APP_KEY'))->toBeNull();
});
