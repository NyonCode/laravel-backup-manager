<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\DTOs;

/**
 * Immutable retention configuration.
 */
final readonly class RetentionPolicy
{
    public function __construct(
        public string $strategy,
        public int $keepDaily = 7,
        public int $keepWeekly = 4,
        public int $keepMonthly = 6,
        public int $keepYearly = 2,
        public int $keepLatest = 10,
        public ?int $maxAgeDays = null,
        public ?int $maxStorageBytes = null,
    ) {}

    /**
     * Build a policy from the retention config array, applying profile overrides.
     *
     * @param  array<string, mixed>  $config  The shared backup-manager.retention config.
     * @param  array<string, mixed>|null  $overrides  Optional per-profile overrides.
     */
    public static function fromConfig(array $config, ?array $overrides = null): self
    {
        $config = array_replace_recursive($config, $overrides ?? []);
        $gfs = (array) ($config['gfs'] ?? []);
        $simple = (array) ($config['simple'] ?? []);

        return new self(
            strategy: (string) ($config['strategy'] ?? 'simple'),
            keepDaily: (int) ($gfs['keep_daily'] ?? 7),
            keepWeekly: (int) ($gfs['keep_weekly'] ?? 4),
            keepMonthly: (int) ($gfs['keep_monthly'] ?? 6),
            keepYearly: (int) ($gfs['keep_yearly'] ?? 2),
            keepLatest: (int) ($simple['keep_latest'] ?? 10),
            maxAgeDays: isset($config['max_age_days']) ? (int) $config['max_age_days'] : null,
            maxStorageBytes: isset($config['max_storage_megabytes'])
                ? (int) $config['max_storage_megabytes'] * 1024 * 1024
                : null,
        );
    }
}
