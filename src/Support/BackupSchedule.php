<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Cron\CronExpression;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * When each profile is backed up automatically — the single answer the
 * scheduler and health monitoring both read.
 *
 * A profile's cadence is its own `profiles.{name}.schedule` cron expression.
 * The default profile, which predates per-profile schedules, falls back to the
 * shared `schedule.backup`; any other profile without one of its own is not
 * scheduled at all. That is deliberately not "every profile on the shared
 * cadence": a profile declared for manual or ad-hoc runs must not start
 * writing backups because someone turned the scheduler on.
 *
 * Monitoring needs the *longest* wait between two runs, not the average: a
 * "twice a month" expression (`0 2 1,15 * *`) leaves a 16-day gap at the end of
 * a 31-day month, and a threshold derived from the shorter gap would page
 * someone every month for a backup that is exactly on time.
 */
final class BackupSchedule
{
    /** How far ahead the run times are walked when measuring the gaps. */
    private const HORIZON_DAYS = 400;

    /** Upper bound on the run times walked, for minute-level expressions. */
    private const MAX_RUNS = 1500;

    /** @var array<string, float|null> Longest gap per expression, in hours. */
    private static array $gaps = [];

    public function __construct(
        private readonly Repository $config,
    ) {}

    /** Whether the package registers anything with the Laravel scheduler. */
    public function enabled(): bool
    {
        return (bool) $this->config->get('backup-manager.schedule.enabled', false);
    }

    /**
     * The cron expression `backup:run` is scheduled on for a profile, or null
     * when nothing runs it automatically.
     */
    public function expressionFor(string $profile): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $own = $this->config->get("backup-manager.profiles.{$profile}.schedule");

        if (is_string($own) && trim($own) !== '') {
            return trim($own);
        }

        if ($profile !== $this->defaultProfile()) {
            return null;
        }

        $shared = $this->config->get('backup-manager.schedule.backup');

        return is_string($shared) && trim($shared) !== '' ? trim($shared) : null;
    }

    /**
     * Every profile that is backed up on a schedule, keyed by profile name.
     *
     * @return array<string, string> profile => cron expression
     */
    public function scheduledProfiles(): array
    {
        $scheduled = [];

        foreach (array_keys((array) $this->config->get('backup-manager.profiles', [])) as $profile) {
            $expression = $this->expressionFor((string) $profile);

            if ($expression !== null) {
                $scheduled[(string) $profile] = $expression;
            }
        }

        return $scheduled;
    }

    /**
     * The longest time between two scheduled runs of a profile, in hours, or
     * null when the profile is not scheduled or its expression is not valid.
     */
    public function intervalHours(string $profile): ?float
    {
        $expression = $this->expressionFor($profile);

        return $expression === null ? null : self::longestGapHours($expression);
    }

    /** The next time a profile's backup is due to start, or null when unscheduled. */
    public function nextRunAt(string $profile, ?DateTimeInterface $after = null): ?DateTimeImmutable
    {
        $expression = $this->expressionFor($profile);

        if ($expression === null) {
            return null;
        }

        $moment = $this->moment($after);

        try {
            return DateTimeImmutable::createFromMutable(
                (new CronExpression($expression))->getNextRunDate($moment, 0, false, $moment->getTimezone()->getName()),
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The longest gap between consecutive run times of a cron expression, in
     * hours. Null for an expression the cron parser rejects.
     *
     * Walked from a fixed reference rather than "now", so the answer — and so
     * every threshold derived from it — does not drift with the calendar.
     */
    public static function longestGapHours(string $expression): ?float
    {
        if (array_key_exists($expression, self::$gaps)) {
            return self::$gaps[$expression];
        }

        try {
            $cron = new CronExpression($expression);
            $start = new DateTimeImmutable('2025-01-01 00:00:00', new DateTimeZone('UTC'));
            $end = $start->modify('+'.self::HORIZON_DAYS.' days');

            $previous = DateTimeImmutable::createFromMutable($cron->getNextRunDate($start, 0, true, 'UTC'));
            $longest = 0;

            for ($i = 0; $i < self::MAX_RUNS && $previous < $end; $i++) {
                $next = DateTimeImmutable::createFromMutable($cron->getNextRunDate($previous, 0, false, 'UTC'));
                $longest = max($longest, $next->getTimestamp() - $previous->getTimestamp());
                $previous = $next;
            }

            $gap = $longest > 0 ? $longest / 3600 : null;
        } catch (Throwable) {
            $gap = null;
        }

        return self::$gaps[$expression] = $gap;
    }

    private function defaultProfile(): string
    {
        return (string) $this->config->get('backup-manager.default', 'default');
    }

    private function moment(?DateTimeInterface $after): DateTimeImmutable
    {
        if ($after !== null) {
            return DateTimeImmutable::createFromInterface($after);
        }

        // The scheduler's own clock: Laravel evaluates cron expressions in
        // `app.schedule_timezone`, falling back to the application timezone.
        $timezone = $this->config->get('app.schedule_timezone') ?? $this->config->get('app.timezone');

        return new DateTimeImmutable('now', new DateTimeZone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC'));
    }
}
