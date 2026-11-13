<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Exceptions\IntegrityException;
use Nyoncode\BackupManager\Exceptions\InvalidConfigurationException;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\Support\RetentionSelector;
use Nyoncode\BackupManager\Support\SafePath;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

function hardenedBackup(string $name, int $hoursAgo, int $size = 100): StoredBackup
{
    return new StoredBackup(
        disk: 'local',
        archivePath: "backups/{$name}",
        manifestPath: "backups/{$name}.manifest.json",
        name: $name,
        size: $size,
        createdAt: new DateTimeImmutable("-{$hoursAgo} hours"),
    );
}

it('keeps the newest backup when the age cap would prune everything', function (): void {
    $backups = [hardenedBackup('b', 24 * 40), hardenedBackup('a', 24 * 50)];

    $keep = app(RetentionSelector::class)->keep($backups, new RetentionPolicy(strategy: 'simple', keepLatest: 10, maxAgeDays: 30));

    expect(array_map(fn (StoredBackup $b): string => $b->name, $keep))->toBe(['b']);
});

it('refuses a manifest digest that is not hex — it addresses a blob path', function (): void {
    Checksum::fromArray(['algorithm' => 'sha256', 'digest' => '../../../../etc/passwd']);
})->throws(IntegrityException::class);

it('refuses table names that a dump binary would read as an option', function (string $table): void {
    config()->set('backup-manager.profiles.default.databases.schema_only_tables', [$table]);

    app(ProfileResolver::class)->resolve('default');
})->with(['--result-file=/var/www/public/x.php', "bad\nname", ''])->throws(InvalidConfigurationException::class);

it('collapses dot segments before a selective restore compares paths', function (): void {
    expect(SafePath::lexical('/var/www/app/../../../etc/x'))->toBe('/etc/x');
});

it('removes the plaintext workspace when a backup fails part-way', function (): void {
    $work = sys_get_temp_dir().'/bm-fail-'.uniqid();
    config()->set('backup-manager.working_directory', $work);
    config()->set('backup-manager.profiles.default', [
        'type' => 'database',
        'databases' => ['connections' => ['missing_sqlite']],
        'destinations' => ['local'],
        'path' => 'backups',
    ]);
    config()->set('database.connections.missing_sqlite', ['driver' => 'sqlite', 'database' => "{$work}/nope/none.sqlite"]);
    Storage::fake('local');

    expect(fn () => app(BackupManager::class)->run('default'))->toThrow(BackupManagerException::class);

    expect(glob("{$work}/*") ?: [])->toBe([]);
});

it('never counts, let alone prunes, a file that is not one of its archives', function (): void {
    Storage::fake('local');
    config()->set('backup-manager.profiles.default', [
        'type' => 'files',
        'files' => ['include' => [sys_get_temp_dir().'/bm-none-'.uniqid()]],
        'destinations' => ['local'],
        'path' => 'shared',
    ]);

    Storage::disk('local')->put('shared/invoice-2026.pdf', 'someone else');
    Storage::disk('local')->put('shared/.snapshot-pool.json', '{}');

    expect(app(BackupRepository::class)->list('local', 'shared'))->toBe([]);

    $this->artisan('backup:clean', ['--sync' => true])->assertSuccessful();

    expect(Storage::disk('local')->exists('shared/invoice-2026.pdf'))->toBeTrue();
});

it('names an archive "backup" when the profile slugs to nothing, so listings still see it', function (): void {
    $work = sys_get_temp_dir().'/bm-slug-'.uniqid();
    mkdir("{$work}/site", 0777, true);
    file_put_contents("{$work}/site/a.txt", 'a');
    Storage::fake('local');
    config()->set('backup-manager.working_directory', "{$work}/temp");
    config()->set('backup-manager.profiles', ['日本' => ['type' => 'files', 'files' => ['include' => ["{$work}/site"]], 'destinations' => ['local'], 'path' => 'backups']]);

    $result = app(BackupManager::class)->run('日本');

    expect($result->archiveFileName)->toStartWith('backup-')
        ->and(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(1);
});
