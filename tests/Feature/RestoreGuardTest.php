<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Enums\BackupType;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Support\RestoreGuard;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\DatabaseArtifact;
use Nyoncode\BackupManager\ValueObjects\EnvironmentInfo;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;

function guardManifest(string $absoluteKeep, string $absoluteNew): Manifest
{
    return new Manifest(
        name: 'X',
        type: BackupType::Full,
        createdAt: new DateTimeImmutable,
        environment: new EnvironmentInfo('X', null, '12', '8.3', 'testing', 'Linux'),
        compression: CompressionAlgorithm::None,
        encryption: EncryptionAlgorithm::None,
        databases: [new DatabaseArtifact('bm_sqlite', DatabaseDriver::Sqlite, 'db', 'databases/bm_sqlite.sqlite')],
        files: [
            new ManifestFile('files/'.ltrim($absoluteKeep, '/'), 10, Checksum::forContents('x')),
            new ManifestFile('files/'.ltrim($absoluteNew, '/'), 10, Checksum::forContents('y')),
        ],
        archiveSize: 0,
        archiveChecksum: Checksum::forContents('a'),
    );
}

it('rolls back databases and files to the pre-restore state', function (): void {
    $work = sys_get_temp_dir().'/bm-guard-'.uniqid();
    mkdir("{$work}/site", 0777, true);
    $dbFile = "{$work}/database.sqlite";
    $keep = "{$work}/site/keep.txt";
    $new = "{$work}/site/created.txt";

    file_put_contents($keep, 'ORIGINAL');
    touch($dbFile);

    config()->set('database.connections.bm_sqlite', [
        'driver' => 'sqlite', 'database' => $dbFile, 'prefix' => '', 'foreign_key_constraints' => false,
    ]);
    DB::connection('bm_sqlite')->statement('CREATE TABLE items (id INTEGER PRIMARY KEY, name TEXT)');
    DB::connection('bm_sqlite')->statement("INSERT INTO items (name) VALUES ('a'),('b')");

    $guard = app(RestoreGuard::class);
    $manifest = guardManifest($keep, $new);
    $resolve = fn (string $c): DatabaseTarget => DatabaseTarget::fromConnection($c, config("database.connections.{$c}"));

    // Snapshot the current, healthy state.
    $point = $guard->capture($manifest, RestoreOptions::full(), "{$work}/rollback", $resolve);

    // Simulate a restore that changed things and then failed.
    DB::connection('bm_sqlite')->statement('DROP TABLE items');
    file_put_contents($keep, 'CORRUPTED BY FAILED RESTORE');
    file_put_contents($new, 'partially written'); // this file did not exist at capture time

    // Compensate.
    $guard->rollback($point);
    DB::purge('bm_sqlite');

    expect(DB::connection('bm_sqlite')->table('items')->count())->toBe(2)          // db restored
        ->and(file_get_contents($keep))->toBe('ORIGINAL')                          // file restored
        ->and(is_file($new))->toBeFalse();                                         // created file removed
});
