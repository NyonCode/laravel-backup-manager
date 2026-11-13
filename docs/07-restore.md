# Restore

Restoring reverses the backup pipeline: the archive is downloaded, its integrity
verified against the manifest, then it is decrypted, decompressed, extracted and
reinstated.

> Database connections are resolved from your **current** environment config, not
> from the archived values — so a backup can be safely restored into staging or a
> fresh host.

## Restore scopes

| Scope | Command |
|-------|---------|
| Everything | `php artisan backup:restore` |
| Databases only | `php artisan backup:restore --database` |
| Files only | `php artisan backup:restore --files` |
| Selected directories | `php artisan backup:restore --only=/var/www/app/config` |

Choose a specific backup by name, or omit it to restore the newest:

```bash
php artisan backup:restore                                  # newest
php artisan backup:restore nightly-2026-07-16-020000.tar.gz.enc
php artisan backup:restore --profile=nightly --disk=s3
```

Restores ask for confirmation (they overwrite data). Use `--force` in automated
contexts.

## Selective restore

Restore only certain directories (matched against the original absolute paths):

```bash
php artisan backup:restore --only=/var/www/app/storage/app/public \
                           --only=/var/www/app/config
```

Selective restore implies files-only.

## Where files are restored

Files are written back to the **absolute paths recorded in the manifest**. By
default a restore is confined to the profile's *current* `files.include` paths:
if any file lies outside them, the restore is refused before anything changes,
and the offending paths are listed.

```text
Restore refused before changing anything: 2 file(s) in this backup lie outside the profile's current files.include paths (/etc/cron.d/backup, /var/www/old-app/.env). Either the manifest was tampered with, or the include paths changed since the backup (e.g. the app moved). If you trust this backup, set backup-manager.restore.confine_to_include_paths to false for this restore.
```

Typical causes are a tampered manifest, or an app that moved to a new server
path. To restore a trusted backup to its recorded paths, turn the check off for
that one restore:

```bash
php artisan backup:restore --allow-outside          # this restore only
BACKUP_RESTORE_CONFINE=false php artisan backup:restore
```

```php
'restore' => [
    'confine_to_include_paths' => (bool) env('BACKUP_RESTORE_CONFINE', true),
],
```

> Leave it on. The manifest is not trusted, the configuration is. An assistant
> cannot change this key over [MCP](14-mcp.md).

File permissions are recorded too (manifest schema 1.3, `files[].mode`) and
restored with each file. Backups made before 1.4 have no recorded mode and
restore with default permissions.

## Unsigned database dumps

`mysql` and `psql` run more than SQL — shell commands, file reads and writes —
and no inspection of a dump's text can rule out every way a tampered one could
reach them. So a database dump is restored only when it can be trusted:

- **The backup is signed** (Pro, `BACKUP_SIGNING=true`) and its signature
  verified — a tampered dump never gets this far; or
- **the client locks itself down**: MariaDB 10.5.25+ / 11.x runs with
  `--sandbox`, MySQL 8.4.6+ with `--commands=OFF`, and psql 13.22, 14.19,
  15.14, 16.10, 17.6 or newer runs the dump inside a `\restrict` with a key
  only the restore knows. Every client command is then refused by the client
  itself.

Otherwise the restore is refused before anything changes. For a backup you
trust, override it once:

```bash
php artisan backup:restore --trust-unsigned-dump
```

`BACKUP_RESTORE_TRUST_UNSIGNED_DUMPS=true` does the same permanently; an
assistant cannot set it over MCP. Files-only restores are not affected.

## Integrity verification

Before extracting, the archive checksum is compared with the manifest. A mismatch
(corruption or tampering) aborts the restore with an `IntegrityException`. Skip
this only if you understand the risk:

```bash
php artisan backup:restore --no-verify
```

## The rollback safety net

A restore overwrites live data, so a failure part-way through could leave the
environment inconsistent. To prevent that, restores are **safe by default**: they
follow a snapshot-and-compensate (saga) strategy.

1. **Before** touching anything, a *pre-restore snapshot* is captured — a dump of
   each affected database and a copy of every file that will be replaced.
2. Files are restored by writing to a temporary file and then **atomically
   renaming** it into place, so a crash never leaves a half-written file.
3. If **any** step fails, the snapshot is replayed to return databases and files
   to exactly how they were, and newly-created files are removed. The original
   error is then re-thrown wrapped in a `RestoreException`.
4. On success, the snapshot is discarded.

If the snapshot itself fails — the pre-restore database dump or the copy of a
file that would be replaced — the restore **aborts before changing anything**
with a `RestoreException`. Carrying on would overwrite live data with no way
back. The one exception is a database that does not exist yet: there is nothing
to roll back to, so it is skipped and logged. `--unsafe` (below) is the
deliberate opt-out.

The snapshot roughly doubles I/O during a restore. Disable it when you are
confident, or restoring into a throwaway environment:

```bash
php artisan backup:restore --unsafe
```

```php
// Programmatically, safety is the last argument of every constructor:
RestoreOptions::full(verifyChecksums: true, safe: true);
RestoreOptions::databasesOnly(safe: false);
```

> **Note on databases:** the compensating rollback restores each database from
> the snapshot dump. Combined with the archive's `DROP TABLE IF EXISTS`, this
> makes both the restore and its rollback idempotent.

## Programmatic restore

```php
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Managers\RestoreManager;

$repository = app(BackupRepository::class);
$backup = $repository->list('s3', 'backups')[0];   // newest

$result = app(RestoreManager::class)->restore($backup, RestoreOptions::full());

$result->restoredDatabases;   // ['mysql']
$result->restoredFileCount;   // 1423
```

Available option constructors:

```php
RestoreOptions::full();
RestoreOptions::databasesOnly();
RestoreOptions::filesOnly();
RestoreOptions::selective(['/var/www/app/config']);
```

## What restore does to databases

The dump is piped into the vendor client (`mysql`, `psql`) or, for SQLite, the
file is copied back. MySQL/PostgreSQL dumps include `DROP TABLE IF EXISTS`, so
restoring onto an existing schema is idempotent.

Before the client runs, the dump is inspected. One that carries `mysql`/`psql`
client commands (`\!`, `system`, `source`, …) is refused with a
`RestoreException` — see [Security](06-security.md#hardening-in-14).
