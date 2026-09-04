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
older than:

```php
'monitoring' => ['max_backup_age_hours' => 25],
```

The command exits non-zero when any destination is unhealthy, so it doubles as a
CI/uptime check.

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
