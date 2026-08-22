<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;

return [

    /*
    |--------------------------------------------------------------------------
    | Default backup profile
    |--------------------------------------------------------------------------
    |
    | The profile used when no profile is specified on the CLI or API. Profiles
    | let you describe multiple, independent backup definitions (e.g. a small
    | hourly database backup and a full nightly backup).
    |
    */

    'default' => env('BACKUP_PROFILE', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Backup profiles
    |--------------------------------------------------------------------------
    |
    | Each profile is a self-contained backup definition. Anything omitted from
    | a profile falls back to the shared defaults defined further below.
    |
    */

    'profiles' => [

        'default' => [

            // A human readable name recorded in the manifest and used for
            // the backup file name prefix.
            'name' => env('APP_NAME', 'Laravel'),

            // What to include: 'full', 'database' or 'files'.
            'type' => 'full',

            // How the backup is stored (independent of 'type'):
            //   'archive'     — a single self-contained tar (compressed/encrypted),
            //                   the reliable standalone anchor. This is the default.
            //   'incremental' — a snapshot whose files live in a shared,
            //                   content-addressed blob pool, so only content that
            //                   changed since the previous snapshot is uploaded.
            //                   Ideal for a frequent (hourly/daily) cadence.
            'mode' => 'archive',

            'databases' => [
                // Laravel connection names to dump. Empty = the default
                // connection resolved from config('database.default').
                'connections' => [],

                // Tables to always exclude (structure and data).
                'exclude_tables' => [],

                // Only dump the schema for these tables, never their rows
                // (useful for large cache/session/telescope tables).
                'schema_only_tables' => [],
            ],

            'files' => [
                // Directories to include in the backup.
                'include' => [
                    base_path(),
                ],

                // Glob-style / path fragments to exclude. These are matched
                // against the path relative to each included root.
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                    base_path('.git'),
                    storage_path('framework'),
                    storage_path('logs'),
                ],

                // Follow symbolic links instead of archiving the link itself.
                'follow_symlinks' => false,

                // Include dot files / hidden files.
                'include_hidden' => true,
            ],

            // Storage destinations (Laravel filesystem disk names). A backup is
            // written to every listed disk. See the 'destinations' note below.
            'destinations' => [
                'local',
            ],

            // Sub-directory within each destination disk.
            'path' => 'backups',

            // Retention overrides for this profile (see shared 'retention').
            'retention' => null,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Compression
    |--------------------------------------------------------------------------
    |
    | Algorithm applied to the backup archive. Supported: 'none', 'gzip',
    | 'bzip2', 'zstd'. Availability depends on the binaries installed on the
    | host; the archiver validates this before running.
    |
    */

    'compression' => [
        'algorithm' => env('BACKUP_COMPRESSION', CompressionAlgorithm::Gzip->value),
        'level' => (int) env('BACKUP_COMPRESSION_LEVEL', 6),
    ],

    /*
    |--------------------------------------------------------------------------
    | Encryption
    |--------------------------------------------------------------------------
    |
    | AES-256 encryption of the final archive. The key must be a 32-byte value;
    | base64: prefixed keys are decoded automatically (matching Laravel's APP_KEY
    | convention). Never commit the key to version control.
    |
    | For incremental ('mode' => 'incremental') profiles this also encrypts the
    | blob pool: blobs are stored as ciphertext but keyed by their plaintext hash,
    | so deduplication is preserved. Treat the algorithm and key as fixed for the
    | life of a pool — like a repository password. Changing them leaves earlier
    | blobs unreadable by later snapshots that expect the new setting.
    |
    */

    'encryption' => [
        'algorithm' => env('BACKUP_ENCRYPTION', EncryptionAlgorithm::None->value),
        'key' => env('BACKUP_ENCRYPTION_KEY'),

        // AES-256-GCM buffers the whole archive in memory (it is authenticated
        // but not streamable). This caps the archive size GCM will accept, in
        // megabytes, failing with a clear error instead of exhausting memory.
        // Use aes-256-cbc (streamed) for larger backups. null disables the cap.
        'gcm_max_megabytes' => (int) env('BACKUP_GCM_MAX_MB', 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Integrity
    |--------------------------------------------------------------------------
    |
    | Checksums are always computed for the archive and for every file recorded
    | in the manifest. Optional HMAC signing authenticates the manifest itself —
    | signing is a Pro capability (nyoncode/laravel-backup-manager-pro); enabling it
    | without Pro installed fails closed rather than storing an unsigned backup.
    |
    */

    'integrity' => [
        'checksum' => env('BACKUP_CHECKSUM', ChecksumAlgorithm::Sha256->value),

        'signing' => [
            // Requires nyoncode/laravel-backup-manager-pro.
            'enabled' => (bool) env('BACKUP_SIGNING', false),
            // Defaults to the encryption key when null.
            'key' => env('BACKUP_SIGNING_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How long backups are kept. The free core ships the `simple` strategy —
    | keep the newest N backups — plus an age cap. The GFS (Grandfather-Father-
    | Son) strategy, which keeps a tapering set of daily/weekly/monthly/yearly
    | backups, and total-storage eviction are Pro capabilities
    | (nyoncode/laravel-backup-manager-pro). Selecting `gfs` without Pro installed prunes
    | nothing (fail-closed); a `max_storage_megabytes` cap without Pro is simply
    | not enforced. Limits that do apply are applied together.
    |
    */

    'retention' => [
        'strategy' => env('BACKUP_RETENTION', 'simple'), // 'simple' (core) | 'gfs' (Pro)

        // Requires nyoncode/laravel-backup-manager-pro.
        'gfs' => [
            'keep_daily' => 7,
            'keep_weekly' => 4,
            'keep_monthly' => 6,
            'keep_yearly' => 2,
        ],

        'simple' => [
            'keep_latest' => 10,
        ],

        // Age cap (core). null disables the limit.
        'max_age_days' => 365,
        // Total-storage cap (Pro). null disables the limit.
        'max_storage_megabytes' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | When enabled, backup and restore work is dispatched to the queue. Set a
    | connection/queue and a limit on concurrent backup jobs.
    |
    */

    'queue' => [
        'enabled' => (bool) env('BACKUP_QUEUE', false),
        'connection' => env('BACKUP_QUEUE_CONNECTION'),
        'queue' => env('BACKUP_QUEUE_NAME', 'backups'),
        'timeout' => (int) env('BACKUP_QUEUE_TIMEOUT', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | Registered automatically with the Laravel scheduler when enabled. Use cron
    | expressions per operation. Set to null to schedule manually in your app.
    |
    */

    'schedule' => [
        'enabled' => (bool) env('BACKUP_SCHEDULE', false),
        'backup' => '0 2 * * *',   // nightly full backup at 02:00
        'cleanup' => '0 4 * * *',  // apply retention at 04:00
        'monitor' => '0 6 * * *',  // health report at 06:00
    ],

    /*
    |--------------------------------------------------------------------------
    | Pro upgrade
    |--------------------------------------------------------------------------
    |
    | Where `php artisan backup:upgrade` fetches the Pro package from. Point this
    | at your private Composer/Satis repository; the command configures the
    | credentials from the licence token you pass it and installs Pro.
    |
    */

    'pro' => [
        'repository' => env('BACKUP_PRO_REPOSITORY', 'https://nyoncode.cz/repo'),
        'package' => 'nyoncode/laravel-backup-manager-pro',
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server (AI assistants)
    |--------------------------------------------------------------------------
    |
    | `php artisan backup:mcp` serves a Model Context Protocol server, so an AI
    | assistant can inspect this installation, propose and write a backup
    | configuration, then run and verify a backup to prove it works.
    |
    | The defaults are deliberately timid. Reading and running backups is always
    | allowed; writing configuration is allowed only in the environments listed
    | below; deleting backups is off entirely until you turn it on. Restoring is
    | never exposed over MCP — it overwrites a live application, so it stays a
    | deliberate human act.
    |
    */

    'mcp' => [
        'enabled' => (bool) env('BACKUP_MCP', true),

        // Let an assistant edit .env and config/backup-manager.php.
        'allow_writes' => (bool) env('BACKUP_MCP_ALLOW_WRITES', true),

        // Let an assistant delete backups (retention pruning). Off by default.
        'allow_destructive' => (bool) env('BACKUP_MCP_ALLOW_DESTRUCTIVE', false),

        // Environments the server may run in at all. ['*'] allows every one.
        'environments' => ['local'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitoring & notifications
    |--------------------------------------------------------------------------
    |
    | The free core notifies on failures by e-mail. Health monitoring
    | (backup:monitor), the persisted history table, unhealthy-destination alerts
    | and the Slack channel are Pro capabilities (nyoncode/laravel-backup-manager-pro);
    | the keys for them stay here so the schema is shared, but without Pro they
    | are simply inert.
    |
    */

    'monitoring' => [
        // Warn when the newest backup is older than this many hours. (Pro:
        // used by health monitoring.)
        'max_backup_age_hours' => 25,

        // Persist an operation history record to the database (Pro; requires the
        // migration). When false, or without Pro, history lives only in the log.
        'store_history' => (bool) env('BACKUP_HISTORY', true),

        'notifications' => [
            'enabled' => (bool) env('BACKUP_NOTIFICATIONS', true),

            // Notify on these events: 'failure' (core), 'unhealthy' (Pro).
            'on' => ['failure', 'unhealthy'],

            'mail' => [
                'to' => array_filter(explode(',', (string) env('BACKUP_NOTIFY_MAIL', ''))),
            ],

            // Slack delivery is a Pro channel.
            'slack' => [
                'webhook_url' => env('BACKUP_NOTIFY_SLACK'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Binaries
    |--------------------------------------------------------------------------
    |
    | Absolute paths to the external tools used for database dumps and
    | compression. Leave null to auto-discover them from the system PATH.
    |
    */

    'binaries' => [
        'mysqldump' => env('BACKUP_MYSQLDUMP_PATH'),
        'mysql' => env('BACKUP_MYSQL_PATH'),

        // MariaDB 11+ renamed its clients and no longer guarantees the old
        // `mysql*` symlinks. A MariaDB connection prefers these and falls back
        // to the MySQL names, so on most hosts neither needs setting.
        'mariadb-dump' => env('BACKUP_MARIADB_DUMP_PATH'),
        'mariadb' => env('BACKUP_MARIADB_PATH'),

        'pg_dump' => env('BACKUP_PGDUMP_PATH'),
        'psql' => env('BACKUP_PSQL_PATH'),
        'gzip' => env('BACKUP_GZIP_PATH'),
        'zstd' => env('BACKUP_ZSTD_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Maximum run time (seconds) for external processes. null = no limit.
    |
    */

    'timeout' => [
        'database' => 3600,
        'process' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Temporary working directory
    |--------------------------------------------------------------------------
    |
    | Where archives are assembled before being pushed to their destinations.
    | Must have enough free space for the largest backup.
    |
    */

    'working_directory' => storage_path('app/backup-manager/temp'),

];
