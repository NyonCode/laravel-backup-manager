<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Events\BackupDeleted;
use Nyoncode\BackupManager\Exceptions\DeletionException;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\DeleteManager;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-delete-'.uniqid();
    $this->filesDir = "{$this->work}/site";

    mkdir("{$this->filesDir}/config", 0777, true);
    file_put_contents("{$this->filesDir}/index.php", '<?php echo "app";');
    file_put_contents("{$this->filesDir}/config/app.php", 'return [];');

    config()->set('backup-manager.default', 'test');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'none');
    config()->set('backup-manager.profiles', [
        'test' => [
            'name' => 'Acme',
            'type' => 'files',
            'databases' => ['connections' => []],
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
});

/**
 * Seed $count backups and return their names, newest first.
 *
 * @return list<string>
 */
function seedBackups(int $count): array
{
    for ($i = 0; $i < $count; $i++) {
        app(BackupManager::class)->run('test');
    }

    return array_map(
        static fn (StoredBackup $b): string => $b->name,
        app(BackupRepository::class)->list('local', 'backups'),
    );
}

it('deletes a named backup and leaves the others alone', function (): void {
    $names = seedBackups(3);

    $deleted = app(DeleteManager::class)->delete('local', 'backups', [$names[0]]);

    expect($deleted)->toHaveCount(1)
        ->and($deleted[0]->name)->toBe($names[0]);

    $remaining = array_map(
        static fn (StoredBackup $b): string => $b->name,
        app(BackupRepository::class)->list('local', 'backups'),
    );

    expect($remaining)->toHaveCount(2)
        ->and($remaining)->not->toContain($names[0])
        ->and($remaining)->toContain($names[1], $names[2]);
});

it('removes the manifest sidecar along with the archive', function (): void {
    $names = seedBackups(2);
    $manifest = StoredBackup::manifestNameFor($names[0]);

    Storage::disk('local')->assertExists("backups/{$manifest}");

    app(DeleteManager::class)->delete('local', 'backups', [$names[0]]);

    Storage::disk('local')->assertMissing("backups/{$names[0]}");
    Storage::disk('local')->assertMissing("backups/{$manifest}");
});

it('deletes several named backups in one call', function (): void {
    $names = seedBackups(4);

    $deleted = app(DeleteManager::class)->delete('local', 'backups', [$names[0], $names[2]]);

    expect($deleted)->toHaveCount(2)
        ->and(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(2);
});

it('refuses to delete the last backup on a destination', function (): void {
    $names = seedBackups(1);

    expect(fn () => app(DeleteManager::class)->delete('local', 'backups', $names))
        ->toThrow(DeletionException::class);

    expect(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(1);
});

it('refuses a selection that would empty the destination, deleting none of it', function (): void {
    $names = seedBackups(3);

    expect(fn () => app(DeleteManager::class)->delete('local', 'backups', $names))
        ->toThrow(DeletionException::class);

    // Fail closed: the refusal is not partial.
    expect(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(3);
});

it('aborts the whole deletion when one name is unknown', function (): void {
    $names = seedBackups(3);

    expect(fn () => app(DeleteManager::class)->delete('local', 'backups', [$names[0], 'not-a-backup.tar.gz']))
        ->toThrow(DeletionException::class);

    expect(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(3);
});

it('dispatches an event carrying the actor for each deletion', function (): void {
    Event::fake([BackupDeleted::class]);

    $names = seedBackups(3);

    app(DeleteManager::class)->delete('local', 'backups', [$names[0], $names[1]], actor: 'ada@example.com');

    Event::assertDispatchedTimes(BackupDeleted::class, 2);
    Event::assertDispatched(BackupDeleted::class, static fn (BackupDeleted $e): bool => $e->actor === 'ada@example.com');
});

it('previews a deletion without removing anything', function (): void {
    $names = seedBackups(3);

    Event::fake([BackupDeleted::class]);

    $preview = app(DeleteManager::class)->delete('local', 'backups', [$names[0]], dryRun: true);

    expect($preview)->toHaveCount(1)
        ->and(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(3);

    Event::assertNotDispatched(BackupDeleted::class);
});

it('does nothing when nothing is named', function (): void {
    seedBackups(2);

    expect(app(DeleteManager::class)->delete('local', 'backups', []))->toBe([]);
    expect(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(2);
});

it('ignores a repeated name instead of counting it twice', function (): void {
    $names = seedBackups(2);

    // Without de-duplication this would look like "2 of 2" and be refused.
    $deleted = app(DeleteManager::class)->delete('local', 'backups', [$names[0], $names[0]]);

    expect($deleted)->toHaveCount(1)
        ->and(app(BackupRepository::class)->list('local', 'backups'))->toHaveCount(1);
});
