---
title: Configuration
description: Every option lives in config/backup-manager.php. This page walks through it section by section.
key: configuration
---
# Configuration

Every option lives in `config/backup-manager.php`. This page walks through it
section by section. The defaults are sensible, so you can leave most of the file
untouched and change only what you need.

## Profiles

A **profile** is a self-contained backup definition — *what* to back up and
*where* to send it. You can define as many as you like; the `default` key decides
which one runs when you don't name one explicitly.

```php
'default' => env('BACKUP_PROFILE', 'default'),

'profiles' => [
    'default' => [
        'name' => env('APP_NAME', 'Laravel'),
        'type' => 'full',                 // full | database | files
        'databases' => [
            'connections' => [],          // [] = the default connection
            'exclude_tables' => [],
            'schema_only_tables' => [],
        ],
        'files' => [
            'include' => [base_path()],
            'exclude' => [base_path('vendor'), base_path('node_modules'), base_path('.git')],
            'follow_symlinks' => false,
            'include_hidden' => true,
        ],
        'destinations' => ['local'],       // Laravel disk names
        'path' => 'backups',               // sub-directory on each disk
        'retention' => null,               // null = use shared retention
    ],
],
```

Add as many profiles as you need, for example a small frequent database backup
and a full nightly one:

```php
'profiles' => [
    'database' => ['type' => 'database', 'destinations' => ['s3']],
    'nightly'  => ['type' => 'full', 'destinations' => ['s3', 'local']],
],
```

Run a specific profile with `php artisan backup:run --profile=nightly`.

## Compression

```php
'compression' => [
    'algorithm' => env('BACKUP_COMPRESSION', 'gzip'),  // none | gzip | bzip2 | zstd
    'level' => (int) env('BACKUP_COMPRESSION_LEVEL', 6),
],
```

`gzip` and `bzip2` are pure-PHP and always available. `zstd` requires the `zstd`
binary. See [Files](04-files.md#compression).

## Encryption

```php
'encryption' => [
    'algorithm' => env('BACKUP_ENCRYPTION', 'none'),   // none | aes-256-cbc | aes-256-gcm
    'key' => env('BACKUP_ENCRYPTION_KEY'),             // 32 bytes, base64: accepted
],
```

See [Security](06-security.md#encryption) for details and trade-offs.

## Integrity

Each backup is checksummed so you can later prove it wasn't corrupted; it can
optionally be signed as well (signing is a Pro feature):

```php
'integrity' => [
    'checksum' => env('BACKUP_CHECKSUM', 'sha256'),    // sha256 | sha512
    'signing' => [
        'enabled' => (bool) env('BACKUP_SIGNING', false),
        'key' => env('BACKUP_SIGNING_KEY'),            // defaults to the encryption key
    ],
],
```

## Retention

```php
'retention' => [
    'strategy' => env('BACKUP_RETENTION', 'gfs'),      // gfs | simple
    'gfs' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_yearly' => 2],
    'simple' => ['keep_latest' => 10],
    'max_age_days' => 365,
    'max_storage_megabytes' => null,
],
```

See [Retention](08-retention.md).

## Queue

Run backups in the background through Laravel's queue, instead of blocking the
console command or request that started them:

```php
'queue' => [
    'enabled' => (bool) env('BACKUP_QUEUE', false),
    'connection' => env('BACKUP_QUEUE_CONNECTION'),
    'queue' => env('BACKUP_QUEUE_NAME', 'backups'),
    'timeout' => (int) env('BACKUP_QUEUE_TIMEOUT', 3600),
    'restore_timeout' => null,   // null = use timeout
],
```

Give backups their own connection rather than reusing the one your web requests
share. Its `retry_after` must be **longer than `timeout`**, or the queue declares
a long backup lost while it is still running and starts a second one beside it —
over the same destination and the same deduplicated blob pool:

```php
// config/queue.php
'backups' => [
    'driver' => 'redis',
    'queue' => 'backups',
    'retry_after' => 7200,   // > backup-manager.queue.timeout
],
```

`restore_timeout` (`BACKUP_QUEUE_RESTORE_TIMEOUT`) covers jobs that restore. A
restore downloads, decrypts, unpacks and takes its own pre-restore snapshot
first, so it usually needs longer than the backup that produced it; `null` uses
`timeout`.

## Scheduling

```php
'schedule' => [
    'enabled' => (bool) env('BACKUP_SCHEDULE', false),
    'backup' => '0 2 * * *',
    'cleanup' => '0 4 * * *',
    'monitor' => '0 6 * * *',
],
```

When enabled, the package registers these commands with the Laravel Scheduler
automatically. See [Scheduling & monitoring](10-scheduling.md).

## Monitoring & notifications

Control the health check and who hears about it when a backup fails or a
destination starts to look unhealthy:

```php
'monitoring' => [
    'max_backup_age_hours' => 25,
    'store_history' => true,
    'notifications' => [
        'enabled' => true,
        'on' => ['failure', 'unhealthy'],   // success | failure | unhealthy
        'mail' => ['to' => []],
        'slack' => ['webhook_url' => env('BACKUP_NOTIFY_SLACK')],
    ],
],
```

## Binaries

Leave `null` to auto-discover from `PATH`, or set absolute paths:

```php
'binaries' => [
    'mysqldump' => env('BACKUP_MYSQLDUMP_PATH'),
    'mysql' => env('BACKUP_MYSQL_PATH'),
    'pg_dump' => env('BACKUP_PGDUMP_PATH'),
    'psql' => env('BACKUP_PSQL_PATH'),
    'zstd' => env('BACKUP_ZSTD_PATH'),
],
```

## Timeouts & workspace

```php
'timeout' => ['database' => 3600, 'process' => 3600],  // seconds; null = unlimited
'working_directory' => storage_path('app/backup-manager/temp'),
```

The working directory must have enough free space for the largest backup while it
is assembled, compressed and encrypted before upload.

## AI assistants (MCP)

```php
'mcp' => [
    'enabled' => (bool) env('BACKUP_MCP', true),
    'allow_writes' => (bool) env('BACKUP_MCP_ALLOW_WRITES', true),
    'allow_destructive' => (bool) env('BACKUP_MCP_ALLOW_DESTRUCTIVE', false),
    'environments' => ['local'],   // ['*'] allows every environment
],
```

What `php artisan backup:mcp` lets an assistant do. Reading and running backups
is always allowed; writing configuration is on by default; **deleting backups is
off** until you enable it, and restore is never exposed. Outside the listed
environments the server refuses to start. See
[AI assistants (MCP)](14-mcp.md).
