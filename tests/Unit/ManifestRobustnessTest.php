<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Exceptions\EncryptionException;
use Nyoncode\BackupManager\Exceptions\ManifestException;
use Nyoncode\BackupManager\Support\EncryptionKey;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

it('throws on malformed manifest JSON', function (): void {
    Manifest::fromJson('{ this is not json');
})->throws(ManifestException::class);

it('throws when required keys are missing', function (): void {
    Manifest::fromJson(json_encode(['name' => 'x', 'type' => 'full']));
})->throws(ManifestException::class);

it('preserves an unknown-but-present schema version on round-trip', function (): void {
    $json = json_encode([
        'schema_version' => '1.0',
        'name' => 'X',
        'type' => 'full',
        'created_at' => '2026-07-16T00:00:00+00:00',
        'environment' => [
            'app_name' => 'X', 'app_version' => null, 'laravel_version' => '12.0',
            'php_version' => '8.3', 'environment' => 'testing', 'operating_system' => 'Linux',
        ],
        'drivers' => ['compression' => 'none', 'encryption' => 'none', 'checksum' => 'sha256'],
        'databases' => [],
        'archive' => ['size' => 0, 'checksum' => ['algorithm' => 'sha256', 'digest' => 'abc']],
        'files' => [],
        'config' => [],
    ]);

    $manifest = Manifest::fromJson($json);

    expect($manifest->schemaVersion)->toBe('1.0')
        ->and($manifest->isEncrypted())->toBeFalse()
        ->and($manifest->fileCount())->toBe(0);
});

describe('StoredBackup::manifestNameFor', function (): void {
    it('derives the manifest name from any archive extension', function (string $archive, string $expected): void {
        expect(StoredBackup::manifestNameFor($archive))->toBe($expected);
    })->with([
        ['nightly-2026-07-16-020000.tar', 'nightly-2026-07-16-020000.manifest.json'],
        ['nightly-2026-07-16-020000.tar.gz', 'nightly-2026-07-16-020000.manifest.json'],
        ['nightly-2026-07-16-020000.tar.gz.enc', 'nightly-2026-07-16-020000.manifest.json'],
        ['nightly-2026-07-16-020000.tar.zst', 'nightly-2026-07-16-020000.manifest.json'],
        ['nightly-2026-07-16-020000.tar.bz2.enc', 'nightly-2026-07-16-020000.manifest.json'],
    ]);
});

describe('EncryptionKey', function (): void {
    it('accepts a raw 32-byte key', function (): void {
        $key = EncryptionKey::fromConfig(str_repeat('a', 32));
        expect(strlen($key->bytes))->toBe(32);
    });

    it('decodes a base64: key', function (): void {
        $raw = random_bytes(32);
        $key = EncryptionKey::fromConfig('base64:'.base64_encode($raw));
        expect($key->bytes)->toBe($raw);
    });

    it('rejects a missing key', function (): void {
        EncryptionKey::fromConfig(null);
    })->throws(EncryptionException::class);

    it('rejects a wrong-length key', function (): void {
        EncryptionKey::fromConfig('too-short');
    })->throws(EncryptionException::class);
});
