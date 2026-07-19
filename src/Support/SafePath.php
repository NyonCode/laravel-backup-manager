<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Exceptions\BackupManagerException;

/**
 * Guards filesystem writes derived from untrusted archive / manifest entry
 * names against path traversal (tar-slip / zip-slip).
 *
 * Restore materialises entries whose names come from an archive or manifest —
 * data that a verified-but-not-necessarily-trusted backup controls. Any entry
 * that would resolve outside its destination root is refused, fail-closed: a
 * corrupt or hostile backup aborts the restore rather than writing a file
 * elsewhere on the host.
 */
final class SafePath
{
    /**
     * Resolve an entry name to an absolute path inside $root, or throw if it
     * escapes. The target need not exist yet; resolution is purely lexical.
     */
    public static function resolveWithin(string $root, string $name): string
    {
        $normalizedRoot = self::normalize(rtrim($root, '/'));
        $target = self::normalize($normalizedRoot.'/'.$name);

        if ($target !== $normalizedRoot && ! str_starts_with($target, $normalizedRoot.'/')) {
            throw new BackupManagerException(
                "Refusing to write entry [{$name}]: it escapes the destination directory."
            );
        }

        return $target;
    }

    /**
     * Lexically collapse '.' and '..' segments without touching the filesystem.
     */
    private static function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '').implode('/', $segments);
    }
}
