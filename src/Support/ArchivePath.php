<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

/**
 * Translates between an absolute filesystem path and its archive entry path.
 *
 * Entries live under a `files/` prefix that mirrors the absolute path with
 * forward slashes. Reconstruction is Windows-aware: a drive-letter path
 * (`C:/…`) is returned as-is, while a POSIX path regains its leading slash.
 */
final class ArchivePath
{
    public const PREFIX = 'files/';

    /**
     * Map an absolute path to its archive entry path (e.g. `/var/www/x` →
     * `files/var/www/x`, `C:\Users\x` → `files/C:/Users/x`).
     */
    public static function fromAbsolute(string $absolute): string
    {
        return self::PREFIX.ltrim(str_replace('\\', '/', $absolute), '/');
    }

    /**
     * Map an archive entry path back to its absolute path.
     */
    public static function toAbsolute(string $archivePath): string
    {
        $relative = str_starts_with($archivePath, self::PREFIX)
            ? substr($archivePath, strlen(self::PREFIX))
            : $archivePath;

        // A Windows drive-letter path (C:/…) is already absolute.
        if (preg_match('#^[A-Za-z]:/#', $relative) === 1) {
            return $relative;
        }

        return '/'.ltrim($relative, '/');
    }

    public static function isFileEntry(string $archivePath): bool
    {
        return str_starts_with($archivePath, self::PREFIX);
    }
}
