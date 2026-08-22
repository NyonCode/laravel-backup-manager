<?php

declare(strict_types=1);

use Nyoncode\BackupManager\ValueObjects\MigrationState;

it('reports what it holds', function (): void {
    $state = new MigrationState(['2024_01_01_000000_create_users', '2024_02_01_000000_add_roles']);

    expect($state->count())->toBe(2)
        ->and($state->latest())->toBe('2024_02_01_000000_add_roles');
});

it('has no latest migration when none has run', function (): void {
    expect((new MigrationState)->latest())->toBeNull()
        ->and((new MigrationState)->count())->toBe(0);
});

/**
 * The direction is the point: restoring a dump that is behind the codebase is a
 * different problem from restoring one that is ahead of it.
 */
it('diffs in the direction it is asked', function (): void {
    $backup = new MigrationState(['a', 'b']);
    $current = new MigrationState(['a', 'b', 'c']);

    expect($current->ahead($backup))->toBe(['c'])
        ->and($backup->ahead($current))->toBe([]);
});

it('recognises an identical schema state', function (): void {
    expect((new MigrationState(['a', 'b']))->matches(new MigrationState(['a', 'b'])))->toBeTrue()
        ->and((new MigrationState(['a', 'b']))->matches(new MigrationState(['b', 'a'])))->toBeFalse();
});

it('round-trips through an array', function (): void {
    $state = new MigrationState(['2024_01_01_000000_create_users', '2024_02_01_000000_add_roles']);

    expect(MigrationState::fromArray($state->toArray())->ran)->toBe($state->ran);
});

it('reads back a manifest written without the field', function (): void {
    expect(MigrationState::fromArray([])->ran)->toBe([]);
});

it('discards entries that are not migration names', function (): void {
    /** @phpstan-ignore-next-line deliberately malformed input */
    $state = MigrationState::fromArray(['ran' => ['a', '', 42, null, 'b']]);

    expect($state->ran)->toBe(['a', 'b']);
});
