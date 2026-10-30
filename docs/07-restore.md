---
title: Restore
description: "Restoring reverses the backup pipeline: the archive is downloaded, its integrity verified against the manifest, then it is decrypted, decompressed,…"
key: restore
---
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
