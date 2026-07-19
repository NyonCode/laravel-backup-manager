<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\RestoreManager;
use Nyoncode\BackupManager\Tests\Integration\DatabaseTestKit;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Ensures a backup can be written to and restored from a real S3-compatible
 * object store (MinIO) — the headline multi-destination path that the other
 * tests only exercise on the local disk.
 */
it('backs up to and restores from real S3-compatible storage (MinIO)', function (): void {
    $s3 = DatabaseTestKit::s3();

    if (! DatabaseTestKit::reachable($s3)) {
        $this->markTestSkipped('MinIO/S3 is not reachable — set BM_S3_* to a running server (default 127.0.0.1:9100).');
    }

    // Create the bucket, retrying until MinIO has finished starting.
    $client = new S3Client([
        'version' => 'latest',
        'region' => $s3['region'],
        'endpoint' => $s3['endpoint'],
        'use_path_style_endpoint' => true,
        'credentials' => ['key' => $s3['key'], 'secret' => $s3['secret']],
    ]);

    $ready = false;
    for ($attempt = 0; $attempt < 15 && ! $ready; $attempt++) {
        try {
            if (! $client->doesBucketExist($s3['bucket'])) {
                $client->createBucket(['Bucket' => $s3['bucket']]);
            }
            $ready = true;
        } catch (Throwable) {
            usleep(500_000);
        }
    }

    if (! $ready) {
        $this->markTestSkipped('MinIO did not become ready in time.');
    }

    // Wire the s3 disk and a real sqlite database.
    $work = sys_get_temp_dir().'/bm-s3-'.uniqid();
    mkdir("{$work}/site", 0777, true);
    file_put_contents("{$work}/site/robots.txt", "User-agent: *\n");
    touch("{$work}/database.sqlite");

    config()->set('filesystems.disks.s3', $s3);
    config()->set('database.connections.bm_sqlite', [
        'driver' => 'sqlite', 'database' => "{$work}/database.sqlite", 'prefix' => '', 'foreign_key_constraints' => false,
    ]);

    $remotePath = 'it-backups-'.uniqid();
    config()->set('backup-manager.default', 's3test');
    config()->set('backup-manager.working_directory', "{$work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'aes-256-gcm');
    config()->set('backup-manager.encryption.key', 'base64:'.base64_encode(random_bytes(32)));
    config()->set('backup-manager.profiles', [
        's3test' => [
            'name' => 'S3 Acme', 'type' => 'full',
            'databases' => ['connections' => ['bm_sqlite']],
            'files' => ['include' => ["{$work}/site"], 'exclude' => []],
            'destinations' => ['s3'], 'path' => $remotePath,
        ],
    ]);

    DB::connection('bm_sqlite')->statement('CREATE TABLE clients (id INTEGER PRIMARY KEY, name TEXT)');
    DB::connection('bm_sqlite')->statement("INSERT INTO clients (name) VALUES ('Ada'),('Grace')");

    // Back up to S3.
    $result = app(BackupManager::class)->run();

    $disk = Storage::disk('s3');
    $manifestName = StoredBackup::manifestNameFor($result->archiveFileName);
    expect($disk->exists("{$remotePath}/{$result->archiveFileName}"))->toBeTrue()
        ->and($disk->exists("{$remotePath}/{$manifestName}"))->toBeTrue();

    // Data loss.
    DB::connection('bm_sqlite')->statement('DROP TABLE clients');

    // Restore straight from S3.
    $backup = app(BackupRepository::class)->list('s3', $remotePath)[0];
    $restore = app(RestoreManager::class)->restore($backup, RestoreOptions::full());
    DB::purge('bm_sqlite');

    expect($restore->restoredDatabases)->toBe(['bm_sqlite'])
        ->and(DB::connection('bm_sqlite')->table('clients')->count())->toBe(2)
        ->and(file_get_contents("{$work}/site/robots.txt"))->toBe("User-agent: *\n");

    // Clean up the remote objects.
    foreach ($disk->files($remotePath) as $file) {
        $disk->delete($file);
    }
})->group('integration', 's3');
