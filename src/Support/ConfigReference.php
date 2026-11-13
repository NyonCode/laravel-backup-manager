<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\ValueObjects\ConfigOption;

/**
 * The package's own configuration, described as data.
 *
 * Two callers depend on it: an assistant reading it to learn what can be set,
 * and {@see ConfigWriter} using it to refuse an unknown key or an impossible
 * value before a single byte is written. Keeping it in code rather than in the
 * documentation matters — `docs/` is not shipped in the Composer distribution,
 * so this file is the only reference an installed package can hand out.
 *
 * When a config key is added, add it here too.
 */
final class ConfigReference
{
    /**
     * @var list<ConfigOption>|null
     */
    private static ?array $options = null;

    /**
     * @return list<ConfigOption>
     */
    public function options(): array
    {
        return self::$options ??= $this->build();
    }

    /**
     * The option describing a concrete config path, or null when unknown.
     */
    public function find(string $path): ?ConfigOption
    {
        foreach ($this->options() as $option) {
            if ($option->matches($path)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The option a `.env` variable feeds, or null when the name is not ours.
     */
    public function findByEnv(string $variable): ?ConfigOption
    {
        foreach ($this->options() as $option) {
            if ($option->env === $variable) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The documented key closest to a mistyped one, to make an error actionable.
     */
    public function suggest(string $path): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $needle = preg_replace('/(?<=profiles\.)[^.]+/', '*', $path) ?? $path;

        foreach ($this->options() as $option) {
            $distance = levenshtein($needle, $option->key);

            if ($distance < $bestDistance) {
                $best = $option->key;
                $bestDistance = $distance;
            }
        }

        return $bestDistance <= max(4, (int) (strlen($needle) / 3)) ? $best : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(): array
    {
        return array_map(static fn (ConfigOption $option): array => $option->toArray(), $this->options());
    }

    /**
     * @return list<ConfigOption>
     */
    private function build(): array
    {
        return [
            new ConfigOption(
                key: 'default',
                type: 'string',
                description: 'Profile used when no profile is named on the CLI or API.',
                env: 'BACKUP_PROFILE',
                default: 'default',
            ),

            // ---- Profiles ------------------------------------------------
            new ConfigOption(
                key: 'profiles.*.name',
                type: 'string',
                description: 'Human readable profile name; recorded in the manifest and used as the archive file name prefix.',
            ),
            new ConfigOption(
                key: 'profiles.*.type',
                type: 'string',
                description: 'What the profile backs up.',
                allowed: ['full', 'database', 'files'],
                default: 'full',
            ),
            new ConfigOption(
                key: 'profiles.*.mode',
                type: 'string',
                description: 'How the backup is stored: a self-contained archive, or an incremental snapshot against a shared, deduplicated blob pool. Incremental requires the Pro package.',
                allowed: ['archive', 'incremental'],
                default: 'archive',
            ),
            new ConfigOption(
                key: 'profiles.*.databases.connections',
                type: 'string[]',
                description: 'Laravel database connection names to dump. Empty uses the application default connection.',
                default: [],
            ),
            new ConfigOption(
                key: 'profiles.*.databases.exclude_tables',
                type: 'string[]',
                description: 'Tables skipped entirely (structure and rows).',
                default: [],
            ),
            new ConfigOption(
                key: 'profiles.*.databases.schema_only_tables',
                type: 'string[]',
                description: 'Tables dumped without their rows — for large cache, session or telemetry tables.',
                default: [],
            ),
            new ConfigOption(
                key: 'profiles.*.files.include',
                type: 'string[]',
                description: 'Absolute directories to back up.',
            ),
            new ConfigOption(
                key: 'profiles.*.files.exclude',
                type: 'string[]',
                description: 'Paths or path fragments to skip, matched relative to each included root.',
            ),
            new ConfigOption(
                key: 'profiles.*.files.follow_symlinks',
                type: 'boolean',
                description: 'Archive the target of a symbolic link instead of the link itself.',
                default: false,
            ),
            new ConfigOption(
                key: 'profiles.*.files.include_hidden',
                type: 'boolean',
                description: 'Include dot files.',
                default: true,
            ),
            new ConfigOption(
                key: 'profiles.*.destinations',
                type: 'string[]',
                description: 'Laravel filesystem disks the backup is written to. Every listed disk receives a copy.',
                default: ['local'],
            ),
            new ConfigOption(
                key: 'profiles.*.path',
                type: 'string',
                description: 'Sub-directory within each destination disk.',
                default: 'backups',
            ),
            new ConfigOption(
                key: 'profiles.*.retention',
                type: 'array?',
                description: 'Retention overrides for this profile, in the shape of the shared `retention` block. Null uses the shared policy.',
                default: null,
            ),
            new ConfigOption(
                key: 'profiles.*.schedule',
                type: 'string?',
                description: 'Cron expression the scheduler backs this profile up on (e.g. `0 2 * * 0` weekly, `0 2 1,15 * *` twice a month). Null: the default profile uses `schedule.backup`, any other profile is not scheduled.',
                default: null,
            ),
            new ConfigOption(
                key: 'profiles.*.monitoring.max_backup_age_hours',
                type: 'integer?',
                description: 'Hours after which this profile\'s newest backup counts as stale. Null derives it from the profile\'s schedule (longest gap between runs plus grace), never below `monitoring.max_backup_age_hours`.',
                default: null,
                requiresPro: true,
            ),

            // ---- Compression ---------------------------------------------
            new ConfigOption(
                key: 'compression.algorithm',
                type: 'string',
                description: 'Compression applied to the archive. bzip2 and zstd need the matching binary on the host.',
                env: 'BACKUP_COMPRESSION',
                allowed: ['none', 'gzip', 'bzip2', 'zstd'],
                default: 'gzip',
            ),
            new ConfigOption(
                key: 'compression.level',
                type: 'integer',
                description: 'Compression level, 1 (fastest) to 9 (smallest).',
                env: 'BACKUP_COMPRESSION_LEVEL',
                default: 6,
            ),

            // ---- Encryption ----------------------------------------------
            new ConfigOption(
                key: 'encryption.algorithm',
                type: 'string',
                description: 'AES-256 encryption of the archive. CBC streams and handles any size; GCM is authenticated but buffers the whole archive in memory.',
                env: 'BACKUP_ENCRYPTION',
                allowed: ['none', 'aes-256-cbc', 'aes-256-gcm'],
                default: 'none',
                noDowngrade: true,
            ),
            new ConfigOption(
                key: 'encryption.key',
                type: 'string?',
                description: 'A 32-byte key, `base64:` prefixed like APP_KEY. Store it in .env and keep a copy somewhere else: without it a backup cannot be restored.',
                env: 'BACKUP_ENCRYPTION_KEY',
                secret: true,
                setOnce: true,
            ),
            new ConfigOption(
                key: 'encryption.allow_legacy_cbc',
                type: 'boolean',
                description: 'Decrypt pre-1.3, unauthenticated AES-256-CBC archives and snapshot blobs of an unsigned backup. Off: such a backup restores only when signed, or with `backup:restore --allow-legacy-cbc`.',
                env: 'BACKUP_ALLOW_LEGACY_CBC',
                default: false,
                guarded: true,
            ),
            new ConfigOption(
                key: 'encryption.gcm_max_megabytes',
                type: 'integer?',
                description: 'Refuse aes-256-gcm above this archive size instead of exhausting memory. Null disables the cap.',
                env: 'BACKUP_GCM_MAX_MB',
                default: 1024,
            ),

            // ---- Integrity -----------------------------------------------
            new ConfigOption(
                key: 'integrity.checksum',
                type: 'string',
                description: 'Hash recorded for the archive and every file in the manifest.',
                env: 'BACKUP_CHECKSUM',
                allowed: ['sha256', 'sha512'],
                default: 'sha256',
            ),
            new ConfigOption(
                key: 'integrity.signing.enabled',
                type: 'boolean',
                description: 'HMAC-sign the manifest (tamper evidence). Enabling it without the Pro package fails the backup rather than storing an unsigned one.',
                env: 'BACKUP_SIGNING',
                default: false,
                requiresPro: true,
                noDowngrade: true,
            ),
            new ConfigOption(
                key: 'integrity.signing.key',
                type: 'string?',
                description: 'Signing key; defaults to the encryption key when null.',
                env: 'BACKUP_SIGNING_KEY',
                requiresPro: true,
                secret: true,
                setOnce: true,
                fallsBackTo: 'encryption.key',
            ),

            // ---- Retention -----------------------------------------------
            new ConfigOption(
                key: 'retention.strategy',
                type: 'string',
                description: 'Which backups survive a `backup:clean` run. `gfs` requires the Pro package; selecting it without Pro prunes nothing.',
                env: 'BACKUP_RETENTION',
                allowed: ['simple', 'gfs'],
                default: 'simple',
            ),
            new ConfigOption(
                key: 'retention.simple.keep_latest',
                type: 'integer',
                description: 'How many of the newest backups the simple strategy keeps.',
                default: 10,
            ),
            new ConfigOption(
                key: 'retention.gfs.keep_daily',
                type: 'integer',
                description: 'Daily backups kept by the GFS strategy.',
                default: 7,
                requiresPro: true,
            ),
            new ConfigOption(
                key: 'retention.gfs.keep_weekly',
                type: 'integer',
                description: 'Weekly backups kept by the GFS strategy.',
                default: 4,
                requiresPro: true,
            ),
            new ConfigOption(
                key: 'retention.gfs.keep_monthly',
                type: 'integer',
                description: 'Monthly backups kept by the GFS strategy.',
                default: 6,
                requiresPro: true,
            ),
            new ConfigOption(
                key: 'retention.gfs.keep_yearly',
                type: 'integer',
                description: 'Yearly backups kept by the GFS strategy.',
                default: 2,
                requiresPro: true,
            ),
            new ConfigOption(
                key: 'retention.max_age_days',
                type: 'integer?',
                description: 'Delete backups older than this many days. Null disables the age cap.',
                default: 365,
            ),
            new ConfigOption(
                key: 'retention.max_storage_megabytes',
                type: 'integer?',
                description: 'Evict the oldest backups once a destination exceeds this size. Requires Pro; without it the cap is not enforced.',
                default: null,
                requiresPro: true,
            ),

            // ---- Queue ---------------------------------------------------
            new ConfigOption(
                key: 'queue.enabled',
                type: 'boolean',
                description: 'Dispatch backup and restore work to the queue instead of running it in the calling process.',
                env: 'BACKUP_QUEUE',
                default: false,
            ),
            new ConfigOption(
                key: 'queue.connection',
                type: 'string?',
                description: 'Queue connection to dispatch on. Null uses the application default.',
                env: 'BACKUP_QUEUE_CONNECTION',
            ),
            new ConfigOption(
                key: 'queue.queue',
                type: 'string',
                description: 'Queue name backup jobs are pushed onto.',
                env: 'BACKUP_QUEUE_NAME',
                default: 'backups',
            ),
            new ConfigOption(
                key: 'queue.timeout',
                type: 'integer',
                description: 'Maximum seconds a queued backup job may run. Keep the connection\'s retry_after above this, or the queue will start a second run beside the first.',
                env: 'BACKUP_QUEUE_TIMEOUT',
                default: 3600,
            ),
            new ConfigOption(
                key: 'queue.restore_timeout',
                type: 'integer?',
                description: 'Maximum seconds a queued restore may run. Null uses queue.timeout; a restore is usually the longer of the two.',
                env: 'BACKUP_QUEUE_RESTORE_TIMEOUT',
            ),

            new ConfigOption(
                key: 'retention.blob_grace_hours',
                type: 'integer',
                description: 'Snapshot pool sweep: unreferenced blobs younger than this are kept, so a snapshot still being written never loses a blob it is about to reference.',
                default: 24,
                requiresPro: true,
            ),

            new ConfigOption(
                key: 'restore.confine_to_include_paths',
                type: 'boolean',
                description: 'Refuse a restore whose files lie outside the profile\'s current files.include paths (a tampered manifest, or an app that moved). Turn off deliberately to restore a trusted backup to its recorded paths.',
                env: 'BACKUP_RESTORE_CONFINE',
                default: true,
                guarded: true,
            ),

            new ConfigOption(
                key: 'restore.trust_unsigned_dumps',
                type: 'boolean',
                description: 'Restore an unsigned database dump even through a client that cannot switch its own commands off (MariaDB --sandbox, MySQL --commands, psql \\restrict). Only for dumps you trust.',
                env: 'BACKUP_RESTORE_TRUST_UNSIGNED_DUMPS',
                default: false,
                guarded: true,
            ),

            // ---- Schedule ------------------------------------------------
            new ConfigOption(
                key: 'schedule.enabled',
                type: 'boolean',
                description: 'Register the backup and cleanup commands with the Laravel scheduler. Without this, backups only run when something calls them.',
                env: 'BACKUP_SCHEDULE',
                default: false,
            ),
            new ConfigOption(
                key: 'schedule.backup',
                type: 'string?',
                description: 'Cron expression for `backup:run` of the default profile (other profiles set `profiles.*.schedule`). Null schedules nothing.',
                default: '0 2 * * *',
            ),
            new ConfigOption(
                key: 'schedule.cleanup',
                type: 'string?',
                description: 'Cron expression for `backup:clean` (retention). Null schedules nothing.',
                default: '0 4 * * *',
            ),
            new ConfigOption(
                key: 'schedule.monitor',
                type: 'string?',
                description: 'Cron expression for the Pro health report `backup:monitor`.',
                default: '0 6 * * *',
                requiresPro: true,
            ),

            // ---- Monitoring & notifications ------------------------------
            new ConfigOption(
                key: 'monitoring.max_backup_age_hours',
                type: 'integer',
                description: 'A destination is unhealthy when its newest backup is older than this. The floor: a scheduled profile gets at least its longest gap between runs plus grace, and `profiles.*.monitoring.max_backup_age_hours` overrides it. Used by Pro health monitoring.',
                default: 25,
                requiresPro: true,
            ),
            new ConfigOption(
                key: 'monitoring.store_history',
                type: 'boolean',
                description: 'Persist an operation history row per run (Pro; needs the migration).',
                env: 'BACKUP_HISTORY',
                default: true,
                requiresPro: true,
            ),
            new ConfigOption(
                key: 'monitoring.notifications.enabled',
                type: 'boolean',
                description: 'Send notifications for the events listed below.',
                env: 'BACKUP_NOTIFICATIONS',
                default: true,
            ),
            new ConfigOption(
                key: 'monitoring.notifications.on',
                type: 'string[]',
                description: 'Events that notify: `failure` (core) and `unhealthy` (Pro).',
                default: ['failure', 'unhealthy'],
            ),
            new ConfigOption(
                key: 'monitoring.notifications.mail.to',
                type: 'string[]',
                description: 'Recipients of failure mail. The env variable takes a comma separated list.',
                env: 'BACKUP_NOTIFY_MAIL',
                default: [],
            ),
            new ConfigOption(
                key: 'monitoring.notifications.slack.webhook_url',
                type: 'string?',
                description: 'Slack incoming webhook. Slack delivery is a Pro channel.',
                env: 'BACKUP_NOTIFY_SLACK',
                requiresPro: true,
                secret: true,
            ),

            // ---- Binaries, timeouts, workspace ---------------------------
            new ConfigOption(
                key: 'binaries.mysqldump',
                type: 'string?',
                description: 'Absolute path to mysqldump. Null auto-discovers it on PATH.',
                env: 'BACKUP_MYSQLDUMP_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.mysql',
                type: 'string?',
                description: 'Absolute path to the mysql client, used when restoring.',
                env: 'BACKUP_MYSQL_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.mariadb-dump',
                type: 'string?',
                description: 'Absolute path to mariadb-dump (MariaDB 11+ names its clients this way).',
                env: 'BACKUP_MARIADB_DUMP_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.mariadb',
                type: 'string?',
                description: 'Absolute path to the mariadb client, used when restoring.',
                env: 'BACKUP_MARIADB_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.pg_dump',
                type: 'string?',
                description: 'Absolute path to pg_dump.',
                env: 'BACKUP_PGDUMP_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.psql',
                type: 'string?',
                description: 'Absolute path to psql, used when restoring.',
                env: 'BACKUP_PSQL_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.gzip',
                type: 'string?',
                description: 'Absolute path to gzip.',
                env: 'BACKUP_GZIP_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'binaries.zstd',
                type: 'string?',
                description: 'Absolute path to zstd.',
                env: 'BACKUP_ZSTD_PATH',
                guarded: true,
            ),
            new ConfigOption(
                key: 'timeout.database',
                type: 'integer?',
                description: 'Seconds a database dump or restore may run. Null means no limit.',
                default: 3600,
            ),
            new ConfigOption(
                key: 'timeout.process',
                type: 'integer?',
                description: 'Seconds any other external process may run. Null means no limit.',
                default: 3600,
            ),
            new ConfigOption(
                key: 'working_directory',
                type: 'string',
                description: 'Where archives are assembled before upload. Needs free space for the largest backup.',
                guarded: true,
            ),

            // ---- MCP -----------------------------------------------------
            new ConfigOption(
                key: 'mcp.enabled',
                type: 'boolean',
                description: 'Whether `backup:mcp` will serve at all.',
                env: 'BACKUP_MCP',
                default: true,
                guarded: true,
            ),
            new ConfigOption(
                key: 'mcp.allow_writes',
                type: 'boolean',
                description: 'Let an assistant edit .env and config/backup-manager.php through the MCP server.',
                env: 'BACKUP_MCP_ALLOW_WRITES',
                default: true,
                guarded: true,
            ),
            new ConfigOption(
                key: 'mcp.allow_destructive',
                type: 'boolean',
                description: 'Let an assistant delete backups (retention pruning) through the MCP server. Off by default.',
                env: 'BACKUP_MCP_ALLOW_DESTRUCTIVE',
                default: false,
                guarded: true,
            ),
            new ConfigOption(
                key: 'mcp.environments',
                type: 'string[]',
                description: 'Application environments the MCP server may run in. `["*"]` allows every environment.',
                default: ['local'],
                guarded: true,
            ),

            // ---- Pro -----------------------------------------------------
            new ConfigOption(
                key: 'pro.repository',
                type: 'string',
                description: 'Composer repository `backup:upgrade` installs the Pro package from.',
                env: 'BACKUP_PRO_REPOSITORY',
                default: 'https://nyoncode.cz/repo',
                guarded: true,
            ),
            new ConfigOption(
                key: 'pro.package',
                type: 'string',
                description: 'Package name of the Pro edition.',
                default: 'nyoncode/laravel-backup-manager-pro',
                guarded: true,
            ),
        ];
    }
}
