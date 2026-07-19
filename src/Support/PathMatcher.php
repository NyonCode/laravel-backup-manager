<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

/**
 * Decides whether an absolute path is excluded by a set of patterns.
 *
 * A pattern matches when it is a directory/file prefix of the path, or when
 * it matches the path (or its basename) as an fnmatch-style glob. This keeps
 * the config expressive: both `base_path('vendor')` and `*.log` work.
 */
final class PathMatcher
{
    /**
     * @param  list<string>  $patterns
     */
    public function isExcluded(string $absolutePath, array $patterns): bool
    {
        $normalized = $this->normalize($absolutePath);

        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }

            $normalizedPattern = $this->normalize($pattern);

            if ($this->isPrefix($normalizedPattern, $normalized)) {
                return true;
            }

            if (fnmatch($normalizedPattern, $normalized) || fnmatch($pattern, basename($absolutePath))) {
                return true;
            }
        }

        return false;
    }

    private function isPrefix(string $prefix, string $path): bool
    {
        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/').'/');
    }

    private function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
