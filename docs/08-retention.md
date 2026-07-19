# Retention

Left alone, backups pile up until they fill the disk. **Retention** is the policy
that decides which historical backups to keep and removes the rest. You apply it
with `php artisan backup:clean`, by hand or on a schedule.

```php
'retention' => [
    'strategy' => 'gfs',        // gfs | simple
    'gfs' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_yearly' => 2],
    'simple' => ['keep_latest' => 10],
    'max_age_days' => 365,
    'max_storage_megabytes' => null,
],
```

## Strategies

### GFS (Grandfather-Father-Son)

> **Pro feature.** GFS retention requires [Backup Manager Pro](13-pro-and-ui.md).
> The free core provides `simple`; a policy naming `gfs` without Pro prunes
> nothing (fail-closed) rather than falling back to a different strategy.

Keeps the newest backup of each of the last N **days**, **weeks**, **months** and
**years**, producing a tapering history — dense recent, sparse old. With the
defaults above you keep the last 7 days, then one per week for 4 weeks, one per
month for 6 months, and one per year for 2 years.

### Simple

Keeps the newest N backups and deletes the rest:

```php
'retention' => ['strategy' => 'simple', 'simple' => ['keep_latest' => 10]],
```

## Additional caps

Applied on top of the chosen strategy:

- **`max_age_days`** — nothing older than this is kept (`null` disables).
- **`max_storage_megabytes`** — once the kept set would exceed this total size,
  the oldest kept backups are dropped until it fits (`null` disables).
  **(Pro** — the free core does not enforce the size cap.)

## Per-profile overrides

A profile can override retention:

```php
'profiles' => [
    'hourly' => [
        'type' => 'database',
        'retention' => ['strategy' => 'simple', 'simple' => ['keep_latest' => 48]],
    ],
],
```

## Previewing

Use `--dry-run` to see what would be deleted without deleting anything:

```bash
php artisan backup:clean --dry-run
php artisan backup:clean --profile=nightly --disk=s3
```

Retention is applied to every destination of the profile (or just `--disk`).

## Programmatic use

```php
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Managers\RetentionManager;

$policy = RetentionPolicy::fromConfig(config('backup-manager.retention'));
$deleted = app(RetentionManager::class)->apply('s3', 'backups', $policy, dryRun: true);
```
