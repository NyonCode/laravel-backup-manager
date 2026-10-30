# Backup Manager for Laravel

[![Tests](https://github.com/nyoncode/laravel-backup-manager/actions/workflows/tests.yml/badge.svg)](https://github.com/nyoncode/laravel-backup-manager/actions/workflows/tests.yml)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%208-brightgreen.svg)](phpstan.neon)
[![PHP Version](https://img.shields.io/badge/php-%5E8.3-8892BF.svg)](composer.json)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A powerful, extensible and **CLI-first** backup manager for Laravel 12+ and 13:
databases, files, AES-256 encryption, integrity manifests and multi-destination
storage — with restore, retention, scheduling, queues and monitoring built in.

It is designed around **composition over inheritance**: every capability
(dumping, compressing, encrypting, hashing, archiving, storing) is a small,
replaceable unit behind an interface, so it is easy to test, extend and to build
a UI on top of.

> 🇨🇿 Kompletní česká dokumentace: [`docs/cs`](docs/cs/README.md).
> The badge URLs assume `github.com/nyoncode/laravel-backup-manager` — adjust if
> your repository lives elsewhere.

---

## Table of contents

- [Why this package](#why-this-package)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Core concepts](#core-concepts)
- [What a backup contains](#what-a-backup-contains)
- [The backup manifest](#the-backup-manifest)
- [Feature tour](#feature-tour)
  - [Databases](#databases)
  - [Files](#files)
  - [Compression](#compression)
  - [Encryption & integrity](#encryption--integrity)
  - [Storage destinations](#storage-destinations)
  - [Restore (with rollback)](#restore-with-rollback)
  - [Retention](#retention)
  - [Scheduling & queues](#scheduling--queues)
  - [Monitoring, history & notifications](#monitoring-history--notifications)
  - [AI assistants (MCP)](#ai-assistants-mcp)
- [CLI reference](#cli-reference)
- [Programmatic API](#programmatic-api)
- [Architecture](#architecture)
- [Testing](#testing)
- [Documentation](#documentation)
- [License](#license)

---

## Why this package

Most Laravel backup tools stop at "zip the app and push it to S3". Backup Manager
goes further:

- **Every backup is self-describing.** A JSON *manifest* records the exact
  environment, drivers, archive checksum and a hash of every file — so a backup
  is verifiable and portable independently of this package.
- **Safe restores.** Restores verify integrity first, and by default take a
  pre-restore snapshot so a failed restore **rolls back** to the previous state.
- **No arbitrary size limits.** The archiver is a streaming TAR implementation
  with GNU extensions: no 8 GiB per-file limit, no 100-byte path limit, flat
  memory usage.
- **Real multi-destination.** Destinations are ordinary Laravel disks, so Local,
  S3, FTP, SFTP, Azure, B2 and WebDAV all work, and you can write to several at
  once.
- **Production plumbing included.** Queues, the scheduler (with
  `withoutOverlapping`), GFS retention, health monitoring, an operation-history
  table and mail/Slack notifications.

---

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.3+ (Laravel 13 needs 8.4+) |
| Laravel | 12.x / 13.x |
| Extensions | `ext-openssl`, `ext-json`; optional `ext-bz2` for bzip2 |

Database dumps use the vendor CLI tools:

| Driver | Dump | Restore |
|--------|------|---------|
| MySQL / MariaDB | `mysqldump` | `mysql` |
| PostgreSQL | `pg_dump` | `psql` |
| SQLite | file copy | file copy |

`zstd` compression needs the `zstd` binary. Remote disks may need their Flysystem
adapter (e.g. `league/flysystem-aws-s3-v3`).

---

## Installation

```bash
composer require nyoncode/laravel-backup-manager
```

Publish the configuration:

```bash
php artisan vendor:publish --tag="backup-manager-config"
```

Optionally publish and run the history migration (see
[Monitoring](#monitoring-history--notifications)):

```bash
php artisan vendor:publish --tag="backup-manager-migrations"
php artisan migrate
```

Full details: [docs/installation.md](docs/01-installation.md).

---

## Quick start

New to the package? The [Getting started tutorial](docs/00-getting-started.md)
walks you from install to a verified, restorable backup in about ten minutes.
Already set up? These are the everyday commands:

```bash
php artisan backup:run          # create a backup (default profile)
php artisan backup:list         # list what's stored
php artisan backup:verify       # verify the newest backup's integrity
php artisan backup:restore      # restore the newest backup (asks to confirm)
php artisan backup:clean        # apply the retention policy
php artisan backup:monitor      # report backup health
```

---

## Core concepts

| Concept | What it is |
|---------|------------|
| **Profile** | A named backup definition (what to back up, where to store it). The `default` profile is used when none is given. You can define as many as you like. |
| **Backup** | One compressed, optionally encrypted TAR archive **plus** a JSON manifest sidecar. |
| **Destination** | A Laravel filesystem disk. A profile may write to several at once. |
| **Retention** | The policy that decides which historical backups to keep. |
| **Restore** | Bringing a backup back — always after verifying integrity, with an optional rollback safety net. |

A profile lives in `config/backup-manager.php`:

```php
'profiles' => [
    'default' => [
        'name' => env('APP_NAME', 'Laravel'),
        'type' => 'full',                       // full | database | files
        'databases' => ['connections' => []],   // [] = default connection
        'files' => [
            'include' => [base_path()],
            'exclude' => [base_path('vendor'), base_path('node_modules'), base_path('.git')],
        ],
        'destinations' => ['local'],             // Laravel disk names
        'path' => 'backups',
    ],
],
```

Run a specific profile with `--profile=name`. See
[docs/configuration.md](docs/02-configuration.md) for every option.

---

## What a backup contains

A single backup produces two objects on each destination:

```text
backups/default-2026-07-16-020000-a1b2c3.tar.gz.enc     ← the archive
backups/default-2026-07-16-020000-a1b2c3.manifest.json  ← the manifest sidecar
```

Inside the (uncompressed, pre-encryption) archive:

```text
databases/mysql.sql              ← one dump per configured connection
files/var/www/app/Models/User.php ← files mirror their absolute path
files/var/www/.env
```

The filename carries the profile, a timestamp and a short random suffix so two
backups never collide, even within the same second.

---

## The backup manifest

The manifest is the feature that makes a backup **self-describing and
verifiable**. Because it is stored unencrypted next to the archive, you can
inspect it and check the archive's checksum **without downloading or decrypting**
the archive.

```json
{
    "schema_version": "1.0",
    "name": "Acme",
    "type": "full",
    "created_at": "2026-07-16T02:00:00+00:00",
    "environment": {
        "app_name": "Acme", "app_version": "1.4.2",
        "laravel_version": "12.0.0", "php_version": "8.3.30",
        "environment": "production", "operating_system": "Linux 6.1.0"
    },
    "drivers": { "compression": "gzip", "encryption": "aes-256-gcm", "checksum": "sha256" },
    "databases": [
        { "connection": "mysql", "driver": "mysql", "database_name": "acme", "archived_path": "databases/mysql.sql" }
    ],
    "archive": { "size": 10485760, "checksum": { "algorithm": "sha256", "digest": "…" } },
    "files": [
        { "path": "files/var/www/app/Models/User.php", "size": 1024, "checksum": { "algorithm": "sha256", "digest": "…" }, "is_symlink": false, "symlink_target": null }
    ],
    "signature": { "algorithm": "sha256", "digest": "…" }
}
```

It never contains secrets — only which algorithms were used.

---

## Feature tour

### Databases

MySQL, MariaDB, PostgreSQL and SQLite. Choose connections, exclude tables, or keep
only a table's structure:

```php
'databases' => [
    'connections' => ['mysql', 'reporting'], // [] = the default connection
    'exclude_tables' => ['telescope_entries'],
    'schema_only_tables' => ['sessions', 'cache'], // structure kept, rows skipped
],
```

Passwords are passed through the environment, never the command line. Details &
the PostgreSQL client-version note: [docs/databases.md](docs/03-databases.md).

### Files

Multiple source roots, prefix/glob excludes, hidden-file and symlink handling:

```php
'files' => [
    'include' => [base_path('app'), base_path('.env'), storage_path('app/public')],
    'exclude' => [base_path('vendor'), '*.log'],
    'follow_symlinks' => false, // record link metadata instead of following
    'include_hidden' => true,
],
```

Details: [docs/files.md](docs/04-files.md).

### Compression

`none`, `gzip`, `bzip2` or `zstd`, streamed so memory stays flat:

```php
'compression' => ['algorithm' => 'gzip', 'level' => 6],
```

### Encryption & integrity

Streaming AES-256 (CBC or authenticated GCM), SHA-256/512 checksums, and optional
HMAC signing of the manifest (signing is a **Pro** feature — see
[Pro & the UI](#backup-manager-pro--the-ui-dashboard)):

```php
'encryption' => [
    'algorithm' => 'aes-256-gcm', // none | aes-256-cbc | aes-256-gcm
    'key' => env('BACKUP_ENCRYPTION_KEY'), // 32 bytes, base64: accepted
],
'integrity' => [
    'checksum' => 'sha256',
    'signing' => ['enabled' => true, 'key' => env('BACKUP_SIGNING_KEY')],
],
```

CBC streams (any size); GCM is authenticated but buffered in memory and capped by
`encryption.gcm_max_megabytes`. Full guidance:
[docs/security.md](docs/06-security.md).

### Storage destinations

Any Laravel disk — write to several at once:

```php
'destinations' => ['s3', 'local'],
```

Local, S3-compatible, FTP, SFTP, Azure Blob, Backblaze B2, WebDAV. Setup examples:
[docs/storage.md](docs/05-storage.md).

### Restore (with rollback)

```bash
php artisan backup:restore                    # newest, everything
php artisan backup:restore --database         # databases only
php artisan backup:restore --files            # files only
php artisan backup:restore --only=/var/www/app/config   # selected directories
```

Restores verify the archive against its manifest first. By default they take a
**pre-restore snapshot** and roll everything back if the restore fails — files
are written via a temporary file and atomic rename. Opt out with `--unsafe`.
Details: [docs/restore.md](docs/07-restore.md).

### Retention

A simple keep-latest count (free core), or Grandfather-Father-Son and a
total-size cap (**Pro**), plus an age cap:

```php
'retention' => [
    'strategy' => 'gfs',
    'gfs' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_yearly' => 2],
    'max_age_days' => 365,
    'max_storage_megabytes' => null,
],
```

```bash
php artisan backup:clean --dry-run   # preview
```

Details: [docs/retention.md](docs/08-retention.md).

### Scheduling & queues

Enable the built-in schedule (registered with `withoutOverlapping`) and/or run
backups on the queue:

```php
'schedule' => ['enabled' => true, 'backup' => '0 2 * * *', 'cleanup' => '0 4 * * *', 'monitor' => '0 6 * * *'],
'queue' => ['enabled' => true, 'queue' => 'backups', 'timeout' => 3600],
```

With the queue enabled, `backup:run` dispatches a `RunBackupJob`; force a
synchronous run with `--sync`. Details:
[docs/scheduling.md](docs/10-scheduling.md).

### Monitoring, history & notifications · Pro

> **Pro.** `backup:monitor`, the `backup_history` table and Slack notifications
> ship in [Backup Manager Pro](#backup-manager-pro--the-ui-dashboard). The free
> core sends failure notifications by mail.

`backup:monitor` reports per-destination health and exits non-zero when
unhealthy (so it doubles as an uptime check). Every operation is logged and, if
the migration is published, stored in a `backup_history` table. Failures and
unhealthy destinations can notify you by mail and/or Slack:

```php
'monitoring' => [
    'max_backup_age_hours' => 25,
    'store_history' => true,
    'notifications' => [
        'enabled' => true,
        'on' => ['failure', 'unhealthy'],
        'mail' => ['to' => ['ops@acme.test']],
        'slack' => ['webhook_url' => env('BACKUP_NOTIFY_SLACK')],
    ],
],
```

### AI assistants (MCP)

```bash
php artisan backup:mcp
```

Serves a [Model Context Protocol](https://modelcontextprotocol.io) server on
stdin/stdout, so an AI assistant can read this installation — disks, database
connections, dump binaries, the resolved profiles, the full configuration
reference — then propose a configuration, write it, and prove it by running and
verifying a real backup. No extra dependency; it ships with the free core.

```bash
claude mcp add backup-manager -- php artisan backup:mcp
```

Writes are bounded: only documented keys with values of the declared type,
a dry run by default that also reports the findings the result *would* have,
`env()`-backed settings routed to `.env`, comments in the config file preserved,
and the previous file copied aside before an atomic write. Deleting backups is a
separate destructive tool that stays hidden until you enable it, and restore is
not exposed over MCP at all.

See [docs/14-mcp.md](docs/14-mcp.md).

---

## Backup Manager Pro & the UI dashboard

This package is the **free MIT core** — a complete, CLI-first backup tool. Two
commercial add-ons plug in through its contracts, with no code changes on your
side:

- **[Backup Manager Pro](../backup-manager-pro)** adds incremental &
  deduplicated backups, GFS retention, HMAC manifest signing, health monitoring
  with history, and Slack notifications.
- **[Backup Manager UI](../backup-manager-ui)** is a live Livewire dashboard to
  run, browse, restore, verify and monitor every destination from one screen
  (requires Pro).

A licence holder upgrades in one command:

```bash
php artisan backup:upgrade <licence-key>
```

Full tier breakdown and the upgrade flow: [docs/13-pro-and-ui.md](docs/13-pro-and-ui.md).

---

## CLI reference

| Command | Purpose |
|---------|---------|
| `backup:run` | Create a backup (`--profile`, `--sync`) |
| `backup:list` | List stored backups (`--profile`, `--disk`) |
| `backup:verify` | Verify a backup against its manifest |
| `backup:restore` | Restore (`--database`, `--files`, `--only`, `--no-verify`, `--unsafe`, `--force`) |
| `backup:clean` | Apply retention (`--dry-run`, `--disk`) |
| `backup:monitor` | Report backup health |
| `backup:mcp` | Serve the MCP server for an AI assistant (`--read-only`) |

Full flags and examples: [docs/cli.md](docs/11-cli.md).

---

## Programmatic API

```php
use Nyoncode\BackupManager\Facades\Backup;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Managers\RestoreManager;
use Nyoncode\BackupManager\DTOs\RestoreOptions;

// Create
$result = Backup::run();                 // or Backup::run('nightly')
$result->archiveFileName;                // nightly-2026-07-16-020000-a1b2c3.tar.gz.enc
$result->manifest->fileCount();

// Restore
$backup = app(BackupRepository::class)->list('s3', 'backups')[0]; // newest
app(RestoreManager::class)->restore($backup, RestoreOptions::databasesOnly());
```

The managers return typed value objects (`BackupResult`, `RestoreResult`,
`HealthReport`, `StoredBackup`, `Manifest`) — ideal for a UI layer.

---

## Architecture

A backup is a **pipeline** of small stages threaded through a shared
`BackupContext`:

```text
PrepareWorkspace → DumpDatabases → CollectSourceFiles → BuildArchive
    → CompressArchive → EncryptArchive → BuildManifest → StoreBackup → CleanupWorkspace
```

Each stage implements `BackupStage` and is injected into `BackupManager`, so you
can add, remove or reorder stages without touching the core. Every capability sits
behind an interface (`DatabaseDumper`, `Compressor`, `Encryptor`, `Signer`,
`Archiver`, `BackupRepository`, `RetentionStrategy`) with a focused
implementation. See [docs/extending.md](docs/12-extending.md).

---

## Testing

```bash
composer test              # Unit + Feature (no database server needed)
composer analyse           # PHPStan level 8
composer test:integration  # real backup ↔ restore against live services
```

The integration suite runs real backup → restore round-trips against live MySQL,
MariaDB, PostgreSQL and MinIO (S3). Point it at your own servers with the `BM_*`
environment variables (see `tests/Integration/DatabaseTestKit.php` for the keys
and defaults); any service that isn't reachable is **skipped automatically**, so
`composer test` stays green without them.

CI ([`.github/workflows/tests.yml`](.github/workflows/tests.yml)) runs:

- **static-analysis** — PHPStan level 8
- **tests** — Unit + Feature across the full support grid: Laravel 12 on
  PHP 8.3 / 8.4 / 8.5 and Laravel 13 on PHP 8.4 / 8.5, plus a lowest-dependencies
  leg
- **windows** — the platform-agnostic Unit suite on `windows-latest`
- **integration** — a matrix leg per engine (MySQL 8.4, MariaDB 11,
  PostgreSQL 16, MinIO/S3), each against its own real service container

---

## Documentation

| Topic | English | Česky |
|-------|---------|-------|
| Overview & index | [docs/README.md](docs/README.md) | [docs/cs/README.md](docs/cs/README.md) |
| Getting started | [getting-started.md](docs/00-getting-started.md) | [zaciname.md](docs/cs/00-zaciname.md) |
| Installation | [installation.md](docs/01-installation.md) | [instalace.md](docs/cs/01-instalace.md) |
| Configuration | [configuration.md](docs/02-configuration.md) | [konfigurace.md](docs/cs/02-konfigurace.md) |
| Databases | [databases.md](docs/03-databases.md) | [databaze.md](docs/cs/03-databaze.md) |
| Files | [files.md](docs/04-files.md) | [soubory.md](docs/cs/04-soubory.md) |
| Storage | [storage.md](docs/05-storage.md) | [uloziste.md](docs/cs/05-uloziste.md) |
| Security & manifest | [security.md](docs/06-security.md) | [zabezpeceni.md](docs/cs/06-zabezpeceni.md) |
| Restore | [restore.md](docs/07-restore.md) | [obnova.md](docs/cs/07-obnova.md) |
| Retention | [retention.md](docs/08-retention.md) | [retence.md](docs/cs/08-retence.md) |
| Scheduling & monitoring | [scheduling.md](docs/10-scheduling.md) | [planovani.md](docs/cs/10-planovani.md) |
| CLI reference | [cli.md](docs/11-cli.md) | [cli.md](docs/cs/11-cli.md) |
| Extending | [extending.md](docs/12-extending.md) | [rozsireni.md](docs/cs/12-rozsireni.md) |

---

## Roadmap

Where the package could go after 1.0 (incremental backups, restorability testing,
a fully streamed pipeline and more): [ROADMAP.md](ROADMAP.md).

## License

The MIT License (MIT). See [LICENSE](LICENSE).
