<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\BackupMode;
use Nyoncode\BackupManager\Enums\BackupType;
use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\ManifestException;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\DatabaseArtifact;
use Nyoncode\BackupManager\ValueObjects\EnvironmentInfo;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;

function makeManifest(): Manifest
{
    return new Manifest(
        name: 'Acme',
        type: BackupType::Full,
        createdAt: new DateTimeImmutable('2026-07-16T02:00:00+00:00'),
        environment: new EnvironmentInfo(
            appName: 'Acme',
            appVersion: '1.4.2',
            laravelVersion: '12.0.0',
            phpVersion: '8.3.30',
            environment: 'production',
            operatingSystem: 'Darwin',
        ),
        compression: CompressionAlgorithm::Gzip,
        encryption: EncryptionAlgorithm::Aes256Gcm,
        databases: [
            new DatabaseArtifact('mysql', DatabaseDriver::MySql, 'acme', 'databases/mysql.sql'),
        ],
        files: [
            new ManifestFile('app/Models/User.php', 1024, Checksum::forContents('user')),
            new ManifestFile('public/logo.svg', 512, Checksum::forContents('logo')),
        ],
        archiveSize: 2048,
        archiveChecksum: Checksum::forContents('archive'),
        config: ['type' => 'full'],
    );
}

it('reports aggregate file statistics', function (): void {
    $manifest = makeManifest();

    expect($manifest->fileCount())->toBe(2)
        ->and($manifest->totalFileSize())->toBe(1536)
        ->and($manifest->isEncrypted())->toBeTrue()
        ->and($manifest->isSigned())->toBeFalse();
});

it('round-trips through JSON without loss', function (): void {
    $manifest = makeManifest();

    $restored = Manifest::fromJson($manifest->toJson());

    expect($restored->toArray())->toEqual($manifest->toArray());
});

it('captures a signature immutably', function (): void {
    $manifest = makeManifest();
    $signature = Checksum::forContents('signature');

    $signed = $manifest->withSignature($signature);

    expect($manifest->isSigned())->toBeFalse()
        ->and($signed->isSigned())->toBeTrue()
        ->and($signed->signature)->toEqual($signature);
});

it('throws when a required key is missing', function (): void {
    Manifest::fromArray(['name' => 'x']);
})->throws(ManifestException::class);

it('records the driver algorithms in its array form', function (): void {
    $data = makeManifest()->toArray();

    expect($data['drivers'])->toMatchArray([
        'compression' => 'gzip',
        'encryption' => 'aes-256-gcm',
        'checksum' => 'sha256',
    ])->and($data['encryption']['enabled'])->toBeTrue();
});

it('defaults to archive mode and round-trips the storage mode', function (): void {
    $archive = makeManifest();
    expect($archive->mode)->toBe(BackupMode::Archive)
        ->and($archive->toArray()['mode'])->toBe('archive');

    $snapshot = Manifest::fromJson($archive->toJson());
    expect($snapshot->mode)->toBe(BackupMode::Archive);
});

it('reads a legacy manifest with no mode as an archive', function (): void {
    $data = makeManifest()->toArray();
    unset($data['mode']);

    expect(Manifest::fromArray($data)->mode)->toBe(BackupMode::Archive);
});

it('derives a stable index checksum independent of file ordering', function (): void {
    $a = new ManifestFile('files/a.txt', 3, Checksum::forContents('aaa'));
    $b = new ManifestFile('files/b.txt', 3, Checksum::forContents('bbb'));

    $forward = Manifest::indexChecksum([$a, $b], ChecksumAlgorithm::Sha256);
    $reversed = Manifest::indexChecksum([$b, $a], ChecksumAlgorithm::Sha256);

    expect($forward->equals($reversed))->toBeTrue();

    // A different set of contents yields a different anchor.
    $c = new ManifestFile('files/a.txt', 3, Checksum::forContents('ccc'));
    $changed = Manifest::indexChecksum([$c, $b], ChecksumAlgorithm::Sha256);

    expect($changed->equals($forward))->toBeFalse();
});
