# Scheduling & monitoring

Backups earn their keep when they run on their own and someone is told if one
fails. This page covers running backups on a schedule, offloading them to a
queue, and monitoring their health.

## Scheduling

Enable the built-in schedule to register the commands with the Laravel Scheduler
automatically:

```php
'schedule' => [
    'enabled' => true,
    'backup' => '0 2 * * *',    // nightly backup at 02:00
    'cleanup' => '0 4 * * *',   // retention at 04:00
    'monitor' => '0 6 * * *',   // health report at 06:00
],
```

Set any expression to `null` to skip registering that command. Make sure your app
runs the scheduler:

```text
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Prefer to wire it yourself? Leave `schedule.enabled = false` and add the commands
to your app's `routes/console.php` / scheduler as usual.

### Per-profile schedules

Give a profile its own cadence with `schedule`:

```php
'profiles' => [
    'default' => ['type' => 'full'],                          // uses schedule.backup
    'reports' => ['type' => 'database', 'schedule' => '0 3 * * 0'],   // weekly
    'archive' => ['type' => 'files'],                         // never scheduled
],
```

- The **default profile** with no `schedule` of its own uses the shared
  `schedule.backup`.
- Any **other profile** with no `schedule` is **not scheduled** — a profile meant
  for manual runs never starts backing up because you turned the scheduler on.

The scheduler registers one command per scheduled profile:

```text
php artisan backup:run                      # default profile, as before
php artisan backup:run --profile=reports
php artisan backup:clean --profile=reports  # on the shared schedule.cleanup
```

Retention (`schedule.cleanup`) and, with Pro, `backup:monitor`
(`schedule.monitor`) run on their shared cadence for the default profile and for
every scheduled profile.

The config validator (`ConfigValidator`, also used by the MCP server and the
dashboard's configuration report) reports an **info** finding for a non-default
profile without a schedule, and an **error** for a `schedule` that is not a valid
five-field cron expression.

## Queues

Long-running backups can be pushed to the queue:

```php
'queue' => [
    'enabled' => true,
    'connection' => env('BACKUP_QUEUE_CONNECTION'),
    'queue' => 'backups',
    'timeout' => 3600,
],
```

When enabled, `backup:run`, `backup:clean` and `backup:monitor` all dispatch a
job to the queue instead of running inline (make sure a worker is processing the
configured queue). Force a synchronous run with `--sync`:

```bash
php artisan backup:run            # queued when queue.enabled = true
php artisan backup:run --sync     # always runs immediately
php artisan backup:clean          # CleanBackupsJob
php artisan backup:monitor        # MonitorBackupsJob (Pro)
```

Retention belongs on the queue as much as the backup does: for an incremental
profile it reference-counts the entire blob pool before sweeping it, which on a
large installation runs for minutes. Scheduled without a queue, that happens
inside the scheduler's own process on your web machine.

`backup:clean --dry-run` never queues — its whole product is the preview it
prints. A queued `backup:monitor` **fails its job** when a destination is
unhealthy, the queue's equivalent of the command's non-zero exit, so it shows up
in `failed_jobs` rather than as a run that went fine.

Scheduled backups are registered with `withoutOverlapping()`, so a slow run
never collides with the next one or with retention/monitoring.

## Monitoring

> **Pro feature.** `backup:monitor`, health reports, the `backup_history` table
> and the `BackupUnhealthy` event are part of
> [Backup Manager Pro](13-pro-and-ui.md). The free core backs up, restores and
> cleans without them.

`php artisan backup:monitor` reports, per destination:

- whether it is **healthy**;
- the number of backups and their total size;
- the age of the newest backup.

A destination is **unhealthy** when it has no backups, or its newest backup is
older than the profile's staleness threshold:

```php
'monitoring' => ['max_backup_age_hours' => 25],   // shared floor
```

The command exits non-zero when any destination is unhealthy, so it doubles as a
CI/uptime check.

### Cadence-aware thresholds

A flat 25 hours would flag a weekly profile every day. The threshold follows the
profile's schedule instead:

1. If `profiles.*.monitoring.max_backup_age_hours` is set, that is the threshold.
2. Otherwise it is the larger of the shared `monitoring.max_backup_age_hours` and
   the **longest gap between two cron runs plus a grace period**.

The grace is 10% of the interval, clamped to 1 hour minimum and 24 hours maximum.
The shared value is a floor — it is never lowered. The *longest* gap is what
counts: `0 2 1,15 * *` leaves a 17-day gap between the 15th and the 1st of a
31-day month, and the threshold covers it.

Worked example — a profile backed up roughly every two weeks:

```php
'profiles' => [
    'archive' => [
        'type' => 'full',
        'schedule' => '0 2 */14 * *',   // runs on the 1st, 15th and 29th
    ],
],
```

> Cron cannot express a true 14-day interval. `*/14` in the day-of-month field
> means days 1, 15 and 29 of each month, then it starts over — so the runs are 14
> days apart, with a shorter gap at the end of the month.

The longest gap is 14 days (336 hours); the grace is 10% of that, 33.6 hours,
capped at 24. The profile stays healthy until its newest backup is older than
about 14 days plus 24 hours (360 hours), and is flagged after that. The shared 25
hours is only a floor, so it does not apply. To pin the value yourself:

```php
'archive' => [
    'schedule' => '0 2 */14 * *',
    'monitoring' => ['max_backup_age_hours' => 400],
],
```

A profile with no schedule falls back to the shared value alone. Monitoring runs
for the default profile and every scheduled profile (`backup:monitor --profile=X`).

## Events

The package dispatches events you can listen to:

| Event | When |
|-------|------|
| `BackupStarted` | before a run begins |
| `BackupCompleted` | after a backup is stored on all destinations |
| `BackupFailed` | when a run throws |
| `BackupUnhealthy` | when monitoring finds an unhealthy destination |

Every lifecycle event is also written to the log by the built-in
`LogsBackupActivity` listener, giving you an audit trail out of the box.

## Notifications

Failures can notify you by **mail** (free core). The **Slack** channel and the
`unhealthy` trigger are [Pro](13-pro-and-ui.md), since they build on monitoring:

```php
'monitoring' => [
    'notifications' => [
        'enabled' => true,
        'on' => ['failure', 'unhealthy'],   // success | failure | unhealthy
        'mail' => ['to' => ['ops@acme.test']],
        'slack' => ['webhook_url' => env('BACKUP_NOTIFY_SLACK')],
    ],
],
```

Notification delivery is best-effort: a failed notification is logged but never
breaks a backup or monitor run.
