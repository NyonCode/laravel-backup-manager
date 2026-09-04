<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Two profiles routinely share a destination — `path` defaults to `backups` for
 * every one of them — and until retention knew which backup belonged to which
 * profile, whichever policy ran first got a vote on the other's archives. A
 * nightly rule applied to fortnightly full backups deletes them silently: no
 * error, no warning, just anchors that are gone the next time anyone looks.
 */
beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-scope-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/a.txt", 'a');

    config()->set('backup-manager.default', 'nightly');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'none');
    config()->set('backup-manager.retention.strategy', 'simple');
    config()->set('backup-manager.retention.simple.keep_latest', 1);

    $profile = [
        'type' => 'files',
        'databases' => ['connections' => []],
        'files' => ['include' => [$this->filesDir], 'exclude' => []],
        'destinations' => ['local'],
        // The shipped default, for both — which is the whole point.
        'path' => 'backups',
    ];

    config()->set('backup-manager.profiles', [
        'nightly' => ['name' => 'Nightly'] + $profile,
        'full' => ['name' => 'Fortnightly full', 'retention' => ['simple' => ['keep_latest' => 5]]] + $profile,
    ]);

    Storage::fake('local');
});

/** @return list<string> Archive names on the destination. */
function archiveNames(): array
{
    return array_values(array_map(
        static fn (string $file): string => basename($file),
        array_filter(
            Storage::disk('local')->files('backups'),
            static fn (string $file): bool => ! str_ends_with($file, '.manifest.json'),
        ),
    ));
}

function namesStartingWith(string $prefix): array
{
    return array_values(array_filter(archiveNames(), static fn (string $n): bool => str_starts_with($n, $prefix)));
}

function runProfile(string $profile, int $times = 1): void
{
    for ($i = 0; $i < $times; $i++) {
        app(BackupManager::class)->run($profile);
    }
}

it('records the profile on every stored backup', function (): void {
    runProfile('nightly');
    runProfile('full');

    $profiles = array_map(
        static fn (StoredBackup $backup): ?string => $backup->profile,
        app(BackupRepository::class)->list('local', 'backups'),
    );

    sort($profiles);

    expect($profiles)->toBe(['full', 'nightly']);
});

it('leaves another profile alone when cleaning the default one', function (): void {
    runProfile('nightly', 2);
    runProfile('full', 2);

    $this->artisan('backup:clean')->assertExitCode(0);

    // `full` keeps 5, so both of its backups survive a run made under `nightly`'s
    // keep-1 policy — which prunes only its own.
    expect(namesStartingWith('full-'))->toHaveCount(2)
        ->and(namesStartingWith('nightly-'))->toHaveCount(1);
});

it('does not count another profile towards its own keep-set', function (): void {
    runProfile('full', 3);
    runProfile('nightly', 1);

    // keep_latest is 5 for `full`: with four backups in the path, three of them
    // its own, nothing of its own is over the limit.
    $this->artisan('backup:clean --profile=full')->assertExitCode(0);

    expect(namesStartingWith('full-'))->toHaveCount(3)
        ->and(namesStartingWith('nightly-'))->toHaveCount(1);
});

it('lists only the profile asked for', function (): void {
    runProfile('nightly', 2);
    runProfile('full', 1);

    $this->artisan('backup:list --profile=full')
        ->expectsOutputToContain('full-')
        ->doesntExpectOutputToContain('nightly-')
        ->assertExitCode(0);
});

/**
 * Backups taken before the manifest carried a profile must keep being cleaned
 * up. Fail-open is the safe direction here: sparing every legacy archive for
 * ever fills the disk just as silently as the bug it replaces, and treating them
 * as this profile's is exactly what the old behaviour did.
 */
it('still prunes a backup whose manifest predates profile tagging', function (): void {
    runProfile('nightly', 2);

    foreach (Storage::disk('local')->files('backups') as $file) {
        if (! str_ends_with($file, '.manifest.json')) {
            continue;
        }

        $manifest = json_decode((string) Storage::disk('local')->get($file), true);
        unset($manifest['config']['profile']);
        Storage::disk('local')->put($file, (string) json_encode($manifest));
    }

    $this->artisan('backup:clean')->assertExitCode(0);

    expect(archiveNames())->toHaveCount(1);
});

it('reports an untagged backup as belonging to whoever asks', function (): void {
    $backup = new StoredBackup(
        disk: 'local',
        archivePath: 'backups/legacy.tar.gz',
        manifestPath: 'backups/legacy.manifest.json',
        name: 'legacy.tar.gz',
        size: 1,
        createdAt: new DateTimeImmutable,
    );

    expect($backup->belongsTo('anything'))->toBeTrue()
        ->and($backup->belongsTo(null))->toBeTrue();
});

it('keeps a tagged backup out of another profile', function (): void {
    $backup = new StoredBackup(
        disk: 'local',
        archivePath: 'backups/full.tar.gz',
        manifestPath: 'backups/full.manifest.json',
        name: 'full.tar.gz',
        size: 1,
        createdAt: new DateTimeImmutable,
        profile: 'full',
    );

    expect($backup->belongsTo('full'))->toBeTrue()
        ->and($backup->belongsTo('nightly'))->toBeFalse()
        ->and($backup->belongsTo(null))->toBeTrue();
});
