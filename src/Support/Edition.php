<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Composer\InstalledVersions;
use Nyoncode\BackupManagerPro\BackupManagerProServiceProvider;
use Nyoncode\BackupManagerUi\BackupManagerUiServiceProvider;
use Throwable;

/**
 * Which parts of the product are installed.
 *
 * Used to explain a fail-closed refusal ("GFS retention needs Pro") rather than
 * letting a user configure something that will quietly prune nothing.
 */
final class Edition
{
    public const CORE = 'nyoncode/laravel-backup-manager';

    public const PRO = 'nyoncode/laravel-backup-manager-pro';

    public const UI = 'nyoncode/laravel-backup-manager-ui';

    public static function proInstalled(): bool
    {
        return class_exists(BackupManagerProServiceProvider::class);
    }

    public static function uiInstalled(): bool
    {
        return class_exists(BackupManagerUiServiceProvider::class);
    }

    public static function version(string $package): ?string
    {
        if (! class_exists(InstalledVersions::class)) {
            return null;
        }

        try {
            return InstalledVersions::isInstalled($package)
                ? InstalledVersions::getPrettyVersion($package)
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, string|bool|null>
     */
    public static function toArray(): array
    {
        return [
            'edition' => self::proInstalled() ? 'pro' : 'core',
            'core_version' => self::version(self::CORE),
            'pro_installed' => self::proInstalled(),
            'pro_version' => self::version(self::PRO),
            'ui_installed' => self::uiInstalled(),
            'ui_version' => self::version(self::UI),
        ];
    }
}
