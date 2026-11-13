<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Nyoncode\BackupManager\BackupManagerServiceProvider;
use Nyoncode\BackupManager\Support\BackupSchedule;
use Nyoncode\BackupManager\Support\ConfigValidator;
use Nyoncode\BackupManager\ValueObjects\ConfigFinding;

beforeEach(function (): void {
    config()->set('backup-manager.schedule.enabled', true);
    config()->set('backup-manager.schedule.backup', '0 2 * * *');
    config()->set('backup-manager.schedule.cleanup', '0 4 * * *');
    config()->set('backup-manager.profiles.fortnightly', [
        'type' => 'files',
        'destinations' => ['local'],
        'schedule' => '0 3 */14 * *',
    ]);
    config()->set('backup-manager.profiles.adhoc', ['type' => 'files', 'destinations' => ['local']]);
});

/**
 * @return list<string>
 */
function scheduledCommands(): array
{
    // The app is already booted, so the provider's booted() callback runs at once.
    (new ReflectionMethod(BackupManagerServiceProvider::class, 'registerSchedule'))
        ->invoke(new BackupManagerServiceProvider(app()));

    return collect(app(Schedule::class)->events())
        ->map(fn (Event $event): string => preg_replace("/^.*'artisan' /", '', (string) $event->command).' @ '.$event->expression)
        ->values()
        ->all();
}

it('resolves each profile\'s own cadence and leaves the rest unscheduled', function (): void {
    $schedule = app(BackupSchedule::class);

    expect($schedule->scheduledProfiles())->toBe([
        'default' => '0 2 * * *',
        'fortnightly' => '0 3 */14 * *',
    ])
        ->and($schedule->expressionFor('adhoc'))->toBeNull()
        ->and($schedule->intervalHours('default'))->toBe(24.0)
        ->and($schedule->intervalHours('fortnightly'))->toBeGreaterThanOrEqual(14.0 * 24);
});

it('schedules nothing while scheduling is off', function (): void {
    config()->set('backup-manager.schedule.enabled', false);

    expect(app(BackupSchedule::class)->scheduledProfiles())->toBe([])
        ->and(app(BackupSchedule::class)->nextRunAt('fortnightly'))->toBeNull();
});

it('measures the longest gap of irregular expressions', function (): void {
    expect(BackupSchedule::longestGapHours('0 2 * * 1-5'))->toBe(72.0)   // Fri → Mon
        ->and(BackupSchedule::longestGapHours('0 2 1,15 * *'))->toBe(17.0 * 24)
        ->and(BackupSchedule::longestGapHours('not a cron'))->toBeNull();
});

it('gives the next run time of a profile', function (): void {
    $next = app(BackupSchedule::class)->nextRunAt('default', new DateTimeImmutable('2026-03-10 05:00:00'));

    expect($next?->format('Y-m-d H:i'))->toBe('2026-03-11 02:00');
});

it('registers backup and retention runs per scheduled profile', function (): void {
    expect(scheduledCommands())->toEqualCanonicalizing([
        'backup:run @ 0 2 * * *',
        "backup:run --profile='fortnightly' @ 0 3 */14 * *",
        'backup:clean @ 0 4 * * *',
        "backup:clean --profile='fortnightly' @ 0 4 * * *",
    ]);
});

it('still cleans the default profile when only retention is scheduled', function (): void {
    config()->set('backup-manager.schedule.backup', null);
    config()->set('backup-manager.profiles.fortnightly.schedule', null);

    expect(scheduledCommands())->toBe(['backup:clean @ 0 4 * * *']);
});

it('validates per-profile cadence settings', function (): void {
    config()->set('backup-manager.profiles.fortnightly.schedule', '0 2 99 * *');
    config()->set('backup-manager.profiles.fortnightly.monitoring.max_backup_age_hours', -1);

    $findings = collect(app(ConfigValidator::class)->validate(app('config')));
    $keyed = fn (string $key): ?ConfigFinding => $findings->first(fn (ConfigFinding $f): bool => $f->key === $key);

    expect($keyed('profiles.fortnightly.schedule')?->severity?->value)->toBe('error')
        ->and($keyed('profiles.fortnightly.monitoring.max_backup_age_hours')?->severity?->value)->toBe('error')
        ->and($keyed('profiles.adhoc.schedule')?->severity?->value)->toBe('info');
});
