<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Exceptions\ConfigWriteException;

/**
 * Reads and updates the application's `.env`, one variable at a time.
 *
 * An existing variable is edited in place, so comments, ordering and every
 * unrelated line survive; a new one is appended under a section header. This is
 * the safe half of configuration writing — most of what the package needs is an
 * environment variable, and touching a line-oriented file cannot corrupt PHP.
 */
final class EnvFile
{
    private const SECTION = '# Backup Manager';

    public function __construct(private readonly string $path) {}

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * The value of a variable, or null when it is absent or commented out.
     */
    public function get(string $key): ?string
    {
        foreach ($this->lines() as $line) {
            $parsed = $this->parse($line);

            if ($parsed !== null && $parsed[0] === $key) {
                return $parsed[1];
            }
        }

        return null;
    }

    /**
     * Upsert variables.
     *
     * @param  array<string, string>  $values
     * @return list<array{key: string, from: string|null, to: string}> The variables that actually change.
     */
    public function set(array $values, bool $dryRun = false): array
    {
        if ($values === []) {
            return [];
        }

        if (! $this->exists()) {
            throw ConfigWriteException::envFileMissing($this->path);
        }

        $lines = $this->lines();
        $changes = [];
        $append = [];

        foreach ($values as $key => $value) {
            $current = $this->get($key);

            if ($current === $value) {
                continue;
            }

            $changes[] = ['key' => $key, 'from' => $current, 'to' => $value];
            $replaced = false;

            foreach ($lines as $index => $line) {
                $parsed = $this->parse($line);

                if ($parsed === null || $parsed[0] !== $key) {
                    continue;
                }

                $lines[$index] = $key.'='.$this->format($value);
                $replaced = true;
            }

            if (! $replaced) {
                $append[] = $key.'='.$this->format($value);
            }
        }

        if ($changes === [] || $dryRun) {
            return $changes;
        }

        if ($append !== []) {
            if ($lines !== [] && trim((string) end($lines)) !== '') {
                $lines[] = '';
            }

            if (! $this->hasSection($lines)) {
                $lines[] = self::SECTION;
            }

            foreach ($append as $line) {
                $lines[] = $line;
            }
        }

        $this->write(implode("\n", $lines)."\n");

        return $changes;
    }

    /**
     * @return list<string>
     */
    private function lines(): array
    {
        if (! $this->exists()) {
            return [];
        }

        $contents = (string) file_get_contents($this->path);

        return explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim($contents, "\n")));
    }

    /**
     * @param  list<string>  $lines
     */
    private function hasSection(array $lines): bool
    {
        foreach ($lines as $line) {
            if (trim($line) === self::SECTION) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: string}|null The key and its decoded value.
     */
    private function parse(string $line): ?array
    {
        if (preg_match('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $matches) !== 1) {
            return null;
        }

        $value = trim($matches[2]);

        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
            $quote = $value[0];
            $value = substr($value, 1, -1);

            if ($quote === '"') {
                $value = str_replace(['\\"', '\\\\', '\\n'], ['"', '\\', "\n"], $value);
            }
        } else {
            // An unquoted value ends at the first inline comment.
            $value = (string) preg_replace('/\s+#.*$/', '', $value);
        }

        return [$matches[1], $value];
    }

    /**
     * Quote a value when a bare one would not survive a round trip.
     */
    private function format(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_.\/:@,+-]+$/', $value) === 1) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
    }

    private function write(string $contents): void
    {
        $temporary = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($temporary, $contents) === false) {
            throw ConfigWriteException::unwritable($this->path);
        }

        $mode = @fileperms($this->path);

        if (is_int($mode)) {
            @chmod($temporary, $mode & 0777);
        }

        if (! @rename($temporary, $this->path)) {
            @unlink($temporary);

            throw ConfigWriteException::unwritable($this->path);
        }
    }
}
