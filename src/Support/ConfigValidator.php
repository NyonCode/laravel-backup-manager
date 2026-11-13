<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Cron\CronExpression;
use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Contracts\Snapshot\SnapshotDriver;
use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Mcp\Tools\ConfigureTool;
use Nyoncode\BackupManager\ValueObjects\ConfigFinding;
use Throwable;

/**
 * Reads a configuration and reports everything that would make a backup fail —
 * or, worse, quietly succeed while protecting nothing.
 *
 * The checks are deliberately paranoid in one direction only: they warn about a
 * setup that *looks* safe but is not (no off-site copy, no encryption key, a
 * retention policy that would delete every backup, signing that will fail closed
 * without Pro). Nothing here changes state, so it is safe to run against a
 * proposed configuration before writing it — which is exactly what
 * {@see ConfigureTool} does.
 */
final class ConfigValidator
{
    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly SnapshotDriver $snapshots,
        private readonly string $configPath,
        private readonly string $envPath,
    ) {}

    /**
     * @return list<ConfigFinding>
     */
    public function validate(Repository $config): array
    {
        return array_merge(
            $this->checkProfiles($config),
            $this->checkCompression($config),
            $this->checkEncryption($config),
            $this->checkIntegrity($config),
            $this->checkRetention($config),
            $this->checkSchedule($config),
            $this->checkQueue($config),
            $this->checkNotifications($config),
            $this->checkWorkspace($config),
            $this->checkInstallation($config),
        );
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkProfiles(Repository $config): array
    {
        $findings = [];
        /** @var array<string, mixed> $profiles */
        $profiles = (array) $config->get('backup-manager.profiles', []);

        if ($profiles === []) {
            return [ConfigFinding::error('profiles', 'No backup profiles are defined; there is nothing to back up.', 'Add a profile under backup-manager.profiles.')];
        }

        $default = (string) $config->get('backup-manager.default', 'default');

        if (! array_key_exists($default, $profiles)) {
            $findings[] = ConfigFinding::error(
                'default',
                "The default profile [{$default}] does not exist.",
                'Set backup-manager.default to one of: '.implode(', ', array_keys($profiles)).'.',
            );
        }

        $resolver = new ProfileResolver($config);

        foreach (array_keys($profiles) as $name) {
            $name = (string) $name;

            try {
                $definition = $resolver->resolve($name);
            } catch (Throwable $exception) {
                $findings[] = ConfigFinding::error("profiles.{$name}", $exception->getMessage());

                continue;
            }

            $findings = array_merge(
                $findings,
                $this->checkDestinations($config, $definition),
                $this->checkFiles($definition),
                $this->checkDatabases($definition),
                $this->checkMode($definition),
            );
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkDestinations(Repository $config, BackupDefinition $definition): array
    {
        $findings = [];
        $key = "profiles.{$definition->profile}.destinations";

        if ($definition->destinations === []) {
            return [ConfigFinding::error($key, 'The profile has no destination disk, so a backup would be built and then thrown away.', 'List at least one Laravel filesystem disk.')];
        }

        /** @var array<string, mixed> $disks */
        $disks = (array) $config->get('filesystems.disks', []);
        $drivers = [];

        foreach ($definition->destinations as $disk) {
            if (! array_key_exists($disk, $disks)) {
                $findings[] = ConfigFinding::error(
                    $key,
                    "Destination disk [{$disk}] is not configured in filesystems.disks.",
                    'Configure the disk, or point the profile at one of: '.implode(', ', array_keys($disks)).'.',
                );

                continue;
            }

            /** @var array<string, mixed> $diskConfig */
            $diskConfig = (array) $disks[$disk];
            $drivers[] = (string) ($diskConfig['driver'] ?? 'unknown');
        }

        if ($drivers !== [] && array_unique($drivers) === ['local']) {
            $findings[] = ConfigFinding::warning(
                $key,
                'Every destination is a local disk, so a backup shares the fate of the machine it protects.',
                'Add an off-site disk (s3, sftp, …) to the profile destinations.',
            );
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkFiles(BackupDefinition $definition): array
    {
        if (! $definition->type->includesFiles()) {
            return [];
        }

        $findings = [];
        $key = "profiles.{$definition->profile}.files.include";

        if ($definition->files->includePaths === []) {
            return [ConfigFinding::error($key, 'The profile backs up files but includes no directory.')];
        }

        foreach ($definition->files->includePaths as $path) {
            if (! is_dir($path)) {
                $findings[] = ConfigFinding::error($key, "Included path [{$path}] does not exist or is not a directory.");

                continue;
            }

            if (! is_readable($path)) {
                $findings[] = ConfigFinding::error($key, "Included path [{$path}] is not readable by the backup process.");
            }
        }

        $excluded = implode(' ', $definition->files->excludePatterns);

        foreach (['vendor', 'node_modules'] as $noise) {
            if (! str_contains($excluded, $noise)) {
                $findings[] = ConfigFinding::warning(
                    "profiles.{$definition->profile}.files.exclude",
                    "[{$noise}] is not excluded; it is reinstallable and can dominate the archive.",
                    "Add the {$noise} directory to the profile's file exclusions.",
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkDatabases(BackupDefinition $definition): array
    {
        if (! $definition->type->includesDatabases()) {
            return [];
        }

        $findings = [];
        $key = "profiles.{$definition->profile}.databases.connections";

        if ($definition->databases === []) {
            return [ConfigFinding::error($key, 'The profile backs up databases but resolves to no connection.')];
        }

        foreach ($definition->databases as $target) {
            $driver = $target->driver;

            if ($driver === DatabaseDriver::Sqlite) {
                if (! is_file($target->database)) {
                    $findings[] = ConfigFinding::error($key, "SQLite database file [{$target->database}] does not exist.");
                }

                continue;
            }

            if ($this->firstAvailable($driver->dumpBinaries()) === null) {
                $findings[] = ConfigFinding::error(
                    'binaries',
                    sprintf(
                        'No dump binary for the %s connection [%s] was found (looked for: %s).',
                        $driver->label(),
                        $target->connection,
                        implode(', ', $driver->dumpBinaries()),
                    ),
                    'Install the client tools, or set the absolute path under backup-manager.binaries.',
                );
            }

            if ($this->firstAvailable($driver->clientBinaries()) === null) {
                $findings[] = ConfigFinding::warning(
                    'binaries',
                    sprintf(
                        'The %s client needed to *restore* connection [%s] was not found (looked for: %s). Backups would succeed and restores would fail.',
                        $driver->label(),
                        $target->connection,
                        implode(', ', $driver->clientBinaries()),
                    ),
                    'Install the client tools, or set the absolute path under backup-manager.binaries.',
                );
            }
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkMode(BackupDefinition $definition): array
    {
        if ($definition->mode->isIncremental() && ! $this->snapshots->supported()) {
            return [ConfigFinding::error(
                "profiles.{$definition->profile}.mode",
                'Incremental backups require nyoncode/laravel-backup-manager-pro; without it this profile cannot run.',
                'Install Pro (php artisan backup:upgrade), or set the profile mode to "archive".',
            )];
        }

        return [];
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkCompression(Repository $config): array
    {
        $algorithm = CompressionAlgorithm::tryFrom((string) $config->get('backup-manager.compression.algorithm', 'gzip'));

        if ($algorithm === null) {
            return [ConfigFinding::error('compression.algorithm', 'Unknown compression algorithm.', 'Use one of: none, gzip, bzip2, zstd.')];
        }

        $findings = [];

        $missing = match ($algorithm) {
            CompressionAlgorithm::Gzip => extension_loaded('zlib') ? null : 'the zlib PHP extension',
            CompressionAlgorithm::Bzip2 => extension_loaded('bz2') ? null : 'the bz2 PHP extension',
            CompressionAlgorithm::Zstd => $this->binaries->has('zstd') ? null : 'the zstd binary',
            CompressionAlgorithm::None => null,
        };

        if ($missing !== null) {
            $findings[] = ConfigFinding::error(
                'compression.algorithm',
                sprintf('Compression is set to %s but %s is not available on this host.', $algorithm->value, $missing),
                'Install it, or switch backup-manager.compression.algorithm to gzip.',
            );
        }

        $level = (int) $config->get('backup-manager.compression.level', 6);

        if ($algorithm->isEnabled() && ($level < 1 || $level > 9)) {
            $findings[] = ConfigFinding::warning('compression.level', "Compression level {$level} is outside the usual 1–9 range.");
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkEncryption(Repository $config): array
    {
        $algorithm = EncryptionAlgorithm::tryFrom((string) $config->get('backup-manager.encryption.algorithm', 'none'));

        if ($algorithm === null) {
            return [ConfigFinding::error('encryption.algorithm', 'Unknown encryption algorithm.', 'Use one of: none, aes-256-cbc, aes-256-gcm.')];
        }

        $key = $config->get('backup-manager.encryption.key');
        $key = is_string($key) && $key !== '' ? $key : null;

        if ($algorithm === EncryptionAlgorithm::None) {
            $findings = [ConfigFinding::warning(
                'encryption.algorithm',
                'Backups are stored unencrypted: anyone who can read the destination can read the database.',
                'Set backup-manager.encryption.algorithm to aes-256-cbc and configure a key.',
            )];

            if ($key !== null) {
                $findings[] = ConfigFinding::info('encryption.key', 'An encryption key is configured but encryption is switched off.');
            }

            return $findings;
        }

        if ($key === null) {
            return [ConfigFinding::error(
                'encryption.key',
                'Encryption is enabled but no key is configured; every backup will fail.',
                'Set BACKUP_ENCRYPTION_KEY to a 32-byte key, e.g. base64:'.base64_encode(random_bytes(32)).' — and store a copy outside the server.',
            )];
        }

        $findings = [];

        try {
            EncryptionKey::fromConfig($key);
        } catch (Throwable $exception) {
            $findings[] = ConfigFinding::error('encryption.key', $exception->getMessage(), 'The key must decode to exactly 32 bytes.');
        }

        if ((new EnvFile($this->envPath))->get('BACKUP_ENCRYPTION_KEY') === null) {
            $findings[] = ConfigFinding::warning(
                'encryption.key',
                'The encryption key does not come from the environment, so it is likely committed with the config file.',
                'Move the key to .env as BACKUP_ENCRYPTION_KEY.',
            );
        }

        if ($algorithm === EncryptionAlgorithm::Aes256Gcm) {
            $cap = $config->get('backup-manager.encryption.gcm_max_megabytes');
            $findings[] = ConfigFinding::info(
                'encryption.algorithm',
                $cap === null
                    ? 'aes-256-gcm buffers the whole archive in memory and no size cap is set.'
                    : sprintf('aes-256-gcm buffers the whole archive in memory; archives above %d MB are refused — on restore too, so lowering this makes larger existing archives unrestorable until it is raised again.', (int) $cap),
                'Use aes-256-cbc for large backups — it is streamed.',
            );
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkIntegrity(Repository $config): array
    {
        if ((bool) $config->get('backup-manager.integrity.signing.enabled', false) && ! Edition::proInstalled()) {
            return [ConfigFinding::error(
                'integrity.signing.enabled',
                'Manifest signing is enabled but nyoncode/laravel-backup-manager-pro is not installed; backups fail closed rather than being stored unsigned.',
                'Install Pro (php artisan backup:upgrade), or set BACKUP_SIGNING=false.',
            )];
        }

        if (! (bool) $config->get('backup-manager.integrity.signing.enabled', false)) {
            return [];
        }

        $key = $config->get('backup-manager.integrity.signing.key') ?? $config->get('backup-manager.encryption.key');
        $key = is_string($key) ? trim($key) : '';
        $bytes = str_starts_with($key, 'base64:') ? strlen((string) base64_decode(substr($key, 7), true)) : strlen($key);

        if ($key === '') {
            return [ConfigFinding::error(
                'integrity.signing.key',
                'Signing is enabled but there is no key (neither BACKUP_SIGNING_KEY nor BACKUP_ENCRYPTION_KEY); an empty HMAC key can be forged by anyone.',
                'Set BACKUP_SIGNING_KEY to 32 random bytes, e.g. "base64:" followed by base64_encode(random_bytes(32)).',
            )];
        }

        if ($bytes < 32) {
            return [ConfigFinding::warning(
                'integrity.signing.key',
                "The signing key is only {$bytes} bytes; a short HMAC key is easier to guess.",
                'Use 32 random bytes for BACKUP_SIGNING_KEY. Backups already signed keep verifying only with the old key.',
            )];
        }

        return [];
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkRetention(Repository $config): array
    {
        $findings = [];
        $strategy = (string) $config->get('backup-manager.retention.strategy', 'simple');

        if ($strategy === 'gfs' && ! Edition::proInstalled()) {
            $findings[] = ConfigFinding::error(
                'retention.strategy',
                'The GFS retention strategy requires Pro. Without it retention prunes nothing (fail-closed), so backups accumulate until the disk fills.',
                'Install Pro, or set BACKUP_RETENTION=simple.',
            );
        } elseif (! in_array($strategy, ['simple', 'gfs'], true)) {
            $findings[] = ConfigFinding::error('retention.strategy', "Unknown retention strategy [{$strategy}]; nothing would be pruned.", 'Use "simple" or "gfs".');
        }

        $keepLatest = (int) $config->get('backup-manager.retention.simple.keep_latest', 10);

        if ($strategy === 'simple' && $keepLatest < 1) {
            $findings[] = ConfigFinding::error(
                'retention.simple.keep_latest',
                'keep_latest is below 1, so a cleanup run would delete every backup it finds.',
                'Keep at least one backup.',
            );
        }

        $maxAge = $config->get('backup-manager.retention.max_age_days');

        if ($maxAge !== null && (int) $maxAge < 1) {
            $findings[] = ConfigFinding::error(
                'retention.max_age_days',
                'max_age_days is below 1, so every backup is immediately expired.',
                'Use null to disable the age cap.',
            );
        }

        if ($config->get('backup-manager.retention.max_storage_megabytes') !== null && ! Edition::proInstalled()) {
            $findings[] = ConfigFinding::warning(
                'retention.max_storage_megabytes',
                'The storage cap is a Pro capability and is not enforced by the free core.',
                'Install Pro, or rely on keep_latest / max_age_days.',
            );
        }

        return $findings;
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkSchedule(Repository $config): array
    {
        $findings = $this->checkProfileCadence($config);

        if (! (bool) $config->get('backup-manager.schedule.enabled', false)) {
            return [ConfigFinding::warning(
                'schedule.enabled',
                'Scheduling is off: backups only happen when something calls backup:run.',
                'Set BACKUP_SCHEDULE=true and make sure the Laravel scheduler itself runs (cron: php artisan schedule:run).',
            ), ...$findings];
        }

        foreach (['backup', 'cleanup'] as $operation) {
            $expression = $config->get("backup-manager.schedule.{$operation}");

            if (! is_string($expression) || trim($expression) === '') {
                $findings[] = ConfigFinding::warning(
                    "schedule.{$operation}",
                    "Scheduling is enabled but no cron expression is set for {$operation}.",
                    $operation === 'backup' ? 'Use "0 2 * * *" for a nightly run.' : 'Use "0 4 * * *" to apply retention daily.',
                );

                continue;
            }

            if (! $this->isCron($expression)) {
                $findings[] = ConfigFinding::error("schedule.{$operation}", "[{$expression}] is not a five-field cron expression.");
            }
        }

        $default = (string) $config->get('backup-manager.default', 'default');

        foreach (array_keys((array) $config->get('backup-manager.profiles', [])) as $profile) {
            $profile = (string) $profile;
            $own = $config->get("backup-manager.profiles.{$profile}.schedule");

            if ($profile !== $default && (! is_string($own) || trim($own) === '')) {
                $findings[] = ConfigFinding::info(
                    "profiles.{$profile}.schedule",
                    "Profile [{$profile}] has no schedule of its own, so the scheduler never backs it up; only the default profile uses schedule.backup.",
                    'Give it a cron expression, e.g. "0 3 * * *" for nightly or "0 2 1,15 * *" for twice a month.',
                );
            }
        }

        return $findings;
    }

    /**
     * Per-profile cadence settings: a profile's own cron expression and its
     * own staleness threshold. Checked whether or not scheduling is on — a
     * broken expression is broken the moment someone turns it on.
     *
     * @return list<ConfigFinding>
     */
    private function checkProfileCadence(Repository $config): array
    {
        $findings = [];

        foreach (array_keys((array) $config->get('backup-manager.profiles', [])) as $profile) {
            $profile = (string) $profile;
            $own = $config->get("backup-manager.profiles.{$profile}.schedule");

            if ($own !== null && (! is_string($own) || (trim($own) !== '' && ! $this->isCron($own)))) {
                $findings[] = ConfigFinding::error(
                    "profiles.{$profile}.schedule",
                    sprintf('[%s] is not a five-field cron expression.', is_scalar($own) ? (string) $own : get_debug_type($own)),
                    'Use e.g. "0 2 * * *" (nightly), "0 2 * * 0" (weekly) or "0 2 1,15 * *" (twice a month).',
                );
            }

            $maxAge = $config->get("backup-manager.profiles.{$profile}.monitoring.max_backup_age_hours");

            if ($maxAge !== null && (! is_numeric($maxAge) || (float) $maxAge <= 0)) {
                $findings[] = ConfigFinding::error(
                    "profiles.{$profile}.monitoring.max_backup_age_hours",
                    'The staleness threshold must be a positive number of hours.',
                    'Use null to derive it from the profile\'s schedule.',
                );
            }
        }

        return $findings;
    }

    /** A five-field expression the scheduler's own cron parser accepts. */
    private function isCron(string $expression): bool
    {
        if (count(preg_split('/\s+/', trim($expression)) ?: []) !== 5) {
            return false;
        }

        return CronExpression::isValidExpression(trim($expression));
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkQueue(Repository $config): array
    {
        if (! (bool) $config->get('backup-manager.queue.enabled', false)) {
            return [];
        }

        $connection = $config->get('backup-manager.queue.connection') ?? $config->get('queue.default');
        $driver = $config->get("queue.connections.{$connection}.driver");

        if ($driver === 'sync') {
            return [ConfigFinding::warning(
                'queue.enabled',
                'Queued backups are enabled but the queue connection is "sync", so they still run in the calling process.',
                'Point backup-manager.queue.connection at a real queue connection.',
            )];
        }

        return [ConfigFinding::info(
            'queue.enabled',
            sprintf('Backups are dispatched to the [%s] queue; a worker must be running for them to happen.', (string) $config->get('backup-manager.queue.queue', 'backups')),
        )];
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkNotifications(Repository $config): array
    {
        if (! (bool) $config->get('backup-manager.monitoring.notifications.enabled', true)) {
            return [ConfigFinding::warning(
                'monitoring.notifications.enabled',
                'Notifications are off, so a failing backup fails silently.',
                'Enable notifications and set BACKUP_NOTIFY_MAIL.',
            )];
        }

        /** @var array<int, string> $recipients */
        $recipients = (array) $config->get('backup-manager.monitoring.notifications.mail.to', []);

        if (array_filter($recipients) === []) {
            return [ConfigFinding::warning(
                'monitoring.notifications.mail.to',
                'No mail recipient is configured, so a failing backup notifies nobody.',
                'Set BACKUP_NOTIFY_MAIL to one or more comma separated addresses.',
            )];
        }

        return [];
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkWorkspace(Repository $config): array
    {
        $directory = (string) $config->get('backup-manager.working_directory', '');

        if ($directory === '') {
            return [ConfigFinding::error('working_directory', 'No working directory is configured.')];
        }

        $existing = $directory;

        while (! is_dir($existing) && dirname($existing) !== $existing) {
            $existing = dirname($existing);
        }

        if (! is_writable($existing)) {
            return [ConfigFinding::error(
                'working_directory',
                "The working directory [{$directory}] is not writable (checked [{$existing}]); archives cannot be assembled.",
            )];
        }

        $free = @disk_free_space($existing);

        if (is_float($free) && $free < 1_073_741_824) {
            return [ConfigFinding::warning(
                'working_directory',
                sprintf('Only %s free where archives are assembled; a backup needs room for the whole archive.', Bytes::human((int) $free)),
            )];
        }

        return [];
    }

    /**
     * @return list<ConfigFinding>
     */
    private function checkInstallation(Repository $config): array
    {
        $findings = [];

        if (! is_file($this->configPath)) {
            $findings[] = ConfigFinding::info(
                'config',
                'config/backup-manager.php has not been published, so the package defaults are in force.',
                'Run php artisan vendor:publish --tag=backup-manager-config to edit profiles.',
            );
        }

        if ((bool) $config->get('backup-manager.monitoring.store_history', true) && ! Edition::proInstalled()) {
            $findings[] = ConfigFinding::info(
                'monitoring.store_history',
                'Operation history is a Pro capability; without it runs are only recorded in the log.',
            );
        }

        if ((bool) $config->get('backup-manager.mcp.allow_destructive', false)) {
            $findings[] = ConfigFinding::warning(
                'mcp.allow_destructive',
                'The MCP server may delete backups. That is a lot of trust to hand an assistant.',
                'Set BACKUP_MCP_ALLOW_DESTRUCTIVE=false unless you are actively pruning.',
            );
        }

        return $findings;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function firstAvailable(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if ($this->binaries->has($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
