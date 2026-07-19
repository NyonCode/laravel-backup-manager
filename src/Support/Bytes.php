<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

/**
 * Formats byte counts as human-readable strings.
 */
final class Bytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

    public static function human(int $bytes, int $precision = 2): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $power = min((int) floor(log($bytes, 1024)), count(self::UNITS) - 1);
        $value = $bytes / (1024 ** $power);

        return round($value, $precision).' '.self::UNITS[$power];
    }
}
