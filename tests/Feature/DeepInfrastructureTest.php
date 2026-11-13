<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Enums\BackupType;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;
use Nyoncode\BackupManager\Exceptions\InvalidConfigurationException;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\RetentionManager;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Support\ProfileResolver;

function seedBackup(string $disk, string $day): void
{
    $name = "test-{$day}-020000";
    Storage::disk($disk)->put("backups/{$name}.tar.gz", "archive-{$day}");
    Storage::disk($disk)->put("backups/{$name}.manifest.json", '{"schema_version":"1.0"}');
}

describe('DiskBackupRepository', function (): void {
    beforeEach(fn () => Storage::fake('local'));

    it('lists backups newest-first, parsing dates from filenames', function (): void {
        seedBackup('local', '2026-07-14');
        seedBackup('local', '2026-07-16');
        seedBackup('local', '2026-07-15');

        $backups = app(BackupRepository::class)->list('local', 'backups');

        expect($backups)->toHaveCount(3)
            ->and($backups[0]->createdAt->format('Y-m-d'))->toBe('2026-07-16')
            ->and($backups[2]->createdAt->format('Y-m-d'))->toBe('2026-07-14')
            ->and($backups[0]->name)->toBe('test-2026-07-16-020000.tar.gz');
    });

    it('ignores manifest sidecars when listing', function (): void {
        seedBackup('local', '2026-07-16');

        $backups = app(BackupRepository::class)->list('local', 'backups');

        expect($backups)->toHaveCount(1)
            ->and($backups[0]->name)->not->toEndWith('.manifest.json');
    });

    it('reads the manifest sidecar for a backup', function (): void {
        $fullManifest = json_encode([
            'schema_version' => '1.0',
            'name' => 'Acme',
            'type' => 'full',
            'created_at' => '2026-07-16T02:00:00+00:00',
            'environment' => [
                'app_name' => 'Acme', 'app_version' => null, 'laravel_version' => '12.0',
                'php_version' => '8.3', 'environment' => 'testing', 'operating_system' => 'Linux',
            ],
            'drivers' => ['compression' => 'gzip', 'encryption' => 'none', 'checksum' => 'sha256'],
            'databases' => [],
            'archive' => ['size' => 10, 'checksum' => ['algorithm' => 'sha256', 'digest' => str_repeat('a', 64)]],
            'files' => [],
            'config' => [],
        ]);
        Storage::disk('local')->put('backups/test-2026-07-16-020000.tar.gz', 'archive');
        Storage::disk('local')->put('backups/test-2026-07-16-020000.manifest.json', $fullManifest);

        $backup = app(BackupRepository::class)->list('local', 'backups')[0];
        $manifest = app(BackupRepository::class)->readManifest($backup);

        expect($manifest->schemaVersion)->toBe('1.0')
            ->and($manifest->name)->toBe('Acme');
    });

    it('deletes both the archive and its manifest', function (): void {
        seedBackup('local', '2026-07-16');
        $backup = app(BackupRepository::class)->list('local', 'backups')[0];

        app(BackupRepository::class)->delete($backup);

        expect(Storage::disk('local')->exists($backup->archivePath))->toBeFalse()
            ->and(Storage::disk('local')->exists($backup->manifestPath))->toBeFalse();
    });
});

it('applies retention against a real disk, deleting the right files', function (): void {
    Storage::fake('local');
    // 30 daily backups.
    foreach (range(0, 29) as $i) {
        seedBackup('local', (new DateTimeImmutable("2026-07-16 -{$i} days"))->format('Y-m-d'));
    }

    $before = count(Storage::disk('local')->files('backups'));
    expect($before)->toBe(60); // 30 archives + 30 manifests

    // The free core ships the `simple` strategy; this proves real-disk deletion
    // removes both the archive and its manifest. (GFS lives in the Pro package.)
    $policy = new RetentionPolicy(strategy: 'simple', keepLatest: 7);
    $deleted = app(RetentionManager::class)->apply('local', 'backups', $policy);

    $remaining = app(BackupRepository::class)->list('local', 'backups');

    expect(count($deleted))->toBe(23)
        ->and(count($remaining))->toBe(7)
        // the 7 newest survive
        ->and(collect($remaining)->pluck('name'))->toContain('test-2026-07-16-020000.tar.gz')
        // deleted files are actually gone from disk (archive + manifest each)
        ->and(count(Storage::disk('local')->files('backups')))->toBe(count($remaining) * 2);
});

describe('ProfileResolver', function (): void {
    it('resolves a typed definition from config', function (): void {
        config()->set('database.connections.acme', ['driver' => 'mysql', 'database' => 'acme', 'host' => 'h']);
        config()->set('backup-manager.profiles.p', [
            'name' => 'Acme', 'type' => 'full',
            'databases' => ['connections' => ['acme'], 'exclude_tables' => ['t1']],
            'files' => ['include' => ['/srv/app'], 'exclude' => ['*.log']],
            'destinations' => ['s3'], 'path' => 'x',
        ]);

        $def = app(ProfileResolver::class)->resolve('p');

        expect($def->type)->toBe(BackupType::Full)
            ->and($def->databases)->toHaveCount(1)
            ->and($def->databases[0]->driver)->toBe(DatabaseDriver::MySql)
            ->and($def->databases[0]->excludeTables)->toBe(['t1'])
            ->and($def->files->excludePatterns)->toBe(['*.log'])
            ->and($def->destinations)->toBe(['s3']);
    });

    it('skips databases for a files-only profile', function (): void {
        config()->set('backup-manager.profiles.f', ['type' => 'files', 'files' => ['include' => ['/x']]]);

        expect(app(ProfileResolver::class)->resolve('f')->databases)->toBeEmpty();
    });

    it('throws for an unknown profile', function (): void {
        app(ProfileResolver::class)->resolve('does-not-exist');
    })->throws(InvalidConfigurationException::class);
});

describe('BinaryLocator', function (): void {
    it('throws a clear error when a binary cannot be found', function (): void {
        (new BinaryLocator)->locate('definitely-not-a-real-binary-xyz-123');
    })->throws(DatabaseDumpException::class);

    it('honours an explicitly configured path', function (): void {
        $locator = new BinaryLocator(['mytool' => '/usr/bin/env']);

        expect($locator->locate('mytool'))->toBe('/usr/bin/env')
            ->and($locator->has('mytool'))->toBeTrue();
    });
});

it('refuses to create an empty backup with nothing to archive', function (): void {
    $work = sys_get_temp_dir().'/bm-empty-'.uniqid();
    mkdir("{$work}/empty", 0777, true);
    Storage::fake('local');

    config()->set('backup-manager.default', 'empty');
    config()->set('backup-manager.working_directory', "{$work}/temp");
    config()->set('backup-manager.profiles', [
        'empty' => [
            'name' => 'Empty', 'type' => 'files',
            'files' => ['include' => ["{$work}/empty"], 'exclude' => []],
            'destinations' => ['local'], 'path' => 'backups',
        ],
    ]);

    app(BackupManager::class)->run();
})->throws(BackupManagerException::class);
