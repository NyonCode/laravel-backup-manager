<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

/**
 * Renders a PHP value as source code, formatted the way a Laravel config file is.
 *
 * Only the literal types a configuration can hold are supported — anything else
 * would produce source that no longer round-trips, which is why an object or a
 * resource is rejected outright rather than exported as something approximate.
 */
final class PhpValue
{
    private const INDENT = '    ';

    private const INLINE_LIMIT = 70;

    public static function export(mixed $value, int $depth = 0): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => self::float($value),
            is_string($value) => self::string($value),
            is_array($value) => self::array($value, $depth),
            default => throw new \InvalidArgumentException(
                sprintf('Values of type %s cannot be written to a configuration file.', get_debug_type($value)),
            ),
        };
    }

    public static function string(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }

    private static function float(float $value): string
    {
        $exported = var_export($value, true);

        return str_contains($exported, '.') || str_contains($exported, 'E') ? $exported : $exported.'.0';
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function array(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);
        $inline = $isList && ! self::containsArray($value);

        if ($inline) {
            $parts = array_map(static fn (mixed $item): string => self::export($item, $depth + 1), $value);
            $line = '['.implode(', ', $parts).']';

            if (strlen($line) + strlen(str_repeat(self::INDENT, $depth)) <= self::INLINE_LIMIT) {
                return $line;
            }
        }

        $indent = str_repeat(self::INDENT, $depth + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $prefix = $isList ? '' : self::key($key).' => ';
            $lines[] = $indent.$prefix.self::export($item, $depth + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat(self::INDENT, $depth).']';
    }

    private static function key(int|string $key): string
    {
        return is_int($key) ? (string) $key : self::string($key);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function containsArray(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                return true;
            }
        }

        return false;
    }
}
