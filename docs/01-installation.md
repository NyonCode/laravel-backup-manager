# Installation

Backup Manager installs like any other Laravel package: require it with Composer,
publish the config file, and you can run your first backup right away. This page
walks you through the requirements and those first steps.

## Requirements

Before you start, check that your environment covers the essentials:

| Requirement | Version |
|-------------|---------|
| PHP | 8.3+ |
| Laravel | 12.x / 13.x |
| Extensions | `ext-openssl`, `ext-json` (bundled) |
| Optional | `ext-bz2` (bzip2), `zstd` binary (zstd compression) |

Database backups run the database vendor's own command-line tools, so you only
need the ones for the databases you actually use:

| Driver | Binary needed |
|--------|---------------|
| MySQL / MariaDB | `mysqldump`, `mysql` (restore) |
| PostgreSQL | `pg_dump`, `psql` (restore) |
| SQLite | none (file copy) |

## Install

```bash
composer require nyoncode/laravel-backup-manager
```

Laravel auto-discovers the service provider, so there's nothing to register by
hand. Next, publish the configuration file so you can adjust it to your needs:

```bash
php artisan vendor:publish --tag="backup-manager-config"
```

This creates `config/backup-manager.php` — the single file where every option
lives.

### Operation history (optional)

Backup Manager can record every run in your database, which is handy for auditing
or for a dashboard later. This step is optional; to enable it, publish and run
the migration:

```bash
php artisan vendor:publish --tag="backup-manager-migrations"
php artisan migrate
```

This creates a `backup_history` table. If you skip this, operations are still
recorded in the log; set `monitoring.store_history` to `false` to disable
database history explicitly.

## Verify

Confirm everything is wired up by creating a backup and then listing what was
stored:

```bash
php artisan backup:run
php artisan backup:list
```

If `mysqldump`/`pg_dump` are not on your `PATH`, set explicit paths under the
`binaries` config key — see [Configuration](02-configuration.md#binaries).

## Environment variables

Prefer not to edit the config file directly? Most settings can be driven straight
from your `.env`. These are the ones you'll reach for most often:

```dotenv
BACKUP_PROFILE=default
BACKUP_COMPRESSION=gzip
BACKUP_ENCRYPTION=none          # aes-256-cbc | aes-256-gcm
BACKUP_ENCRYPTION_KEY=          # base64:... (32 bytes) when encryption is on
BACKUP_RETENTION=gfs            # gfs | simple
BACKUP_SCHEDULE=false
BACKUP_QUEUE=false
```

See [Configuration](02-configuration.md) for the full list.
