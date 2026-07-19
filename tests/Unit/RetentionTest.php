<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Managers\RetentionManager;
use Nyoncode\BackupManager\Retention\SimpleStrategy;
use Nyoncode\BackupManager\Support\RetentionSelector;
use Nyoncode\BackupManager\Support\RetentionStrategyRegistry;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

function backup(string $date, int $size = 1000): StoredBackup
{
    $created = new DateTimeImmutable($date);

    return new StoredBackup(
        disk: 'local',
        archivePath: "backups/b-{$date}.tar.gz",
        manifestPath: "backups/b-{$date}.manifest.json",
        name: "b-{$date}.tar.gz",
        size: $size,
        createdAt: $created,
    );
}

/** @param list<StoredBackup> $backups */
function names(array $backups): array
{
    return array_map(static fn (StoredBackup $b): string => $b->name, $backups);
}

/**
 * The selector as the free core wires it: only the `simple` strategy is
 * registered, and no storage-cap evictor is present.
 */
function coreSelector(): RetentionSelector
{
    $registry = new RetentionStrategyRegistry;
    $registry->register('simple', fn (RetentionPolicy $p): SimpleStrategy => new SimpleStrategy($p->keepLatest));

    return new RetentionSelector($registry);
}

/**
 * @param  list<StoredBackup>  $all
 * @param  list<string>  $deleted  Records the names of deleted backups.
 */
function fakeRepository(array $all, array &$deleted = []): BackupRepository
{
    return new class($all, $deleted) implements BackupRepository
    {
        /** @param list<StoredBackup> $all */
        public function __construct(private array $all, private array &$deleted) {}

        public function list(string $disk, string $path): array
        {
            return $this->all;
        }

        public function listSnapshots(string $disk, string $path): array
        {
            return [];
        }

        public function readManifest(StoredBackup $backup): Manifest
        {
            throw new RuntimeException('not needed');
        }

        public function download(StoredBackup $backup, string $localPath): void {}

        public function delete(StoredBackup $backup): void
        {
            $this->deleted[] = $backup->name;
        }
    };
}

it('keeps the newest N backups with the simple strategy', function (): void {
    $backups = [backup('2026-07-16'), backup('2026-07-15'), backup('2026-07-14'), backup('2026-07-13')];

    $kept = (new SimpleStrategy(2))->keep($backups);

    expect(names($kept))->toBe(['b-2026-07-16.tar.gz', 'b-2026-07-15.tar.gz']);
});

it('deletes everything outside the keep set via the manager', function (): void {
    $all = [backup('2026-07-16'), backup('2026-07-15'), backup('2026-07-14')];
    $repository = fakeRepository($all);

    $policy = new RetentionPolicy(strategy: 'simple', keepLatest: 1);
    $result = (new RetentionManager($repository, coreSelector()))->apply('local', 'backups', $policy);

    expect(names($result))->toBe(['b-2026-07-15.tar.gz', 'b-2026-07-14.tar.gz']);
});

it('honours the max age limit', function (): void {
    $backups = [
        backup((new DateTimeImmutable('-1 day'))->format('Y-m-d')),
        backup((new DateTimeImmutable('-400 days'))->format('Y-m-d')),
    ];
    $repository = fakeRepository($backups);

    $policy = new RetentionPolicy(strategy: 'simple', keepLatest: 10, maxAgeDays: 365);
    $deleted = (new RetentionManager($repository, coreSelector()))->apply('local', 'backups', $policy, dryRun: true);

    expect($deleted)->toHaveCount(1)
        ->and($deleted[0]->createdAt->format('Y'))->not->toBeEmpty();
});

it('prunes nothing when the strategy is unavailable without Pro', function (): void {
    // `gfs` is a Pro strategy; the core registry does not know it. Retention must
    // fail closed — keep every backup rather than prune under a policy it cannot
    // honour — so nothing is deleted.
    $all = [backup('2026-07-16'), backup('2026-07-15'), backup('2026-07-14')];
    $repository = fakeRepository($all);

    $policy = new RetentionPolicy(strategy: 'gfs', keepDaily: 1);
    $deleted = (new RetentionManager($repository, coreSelector()))->apply('local', 'backups', $policy);

    expect($deleted)->toBe([]);
});
