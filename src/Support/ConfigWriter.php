<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Exceptions\ConfigWriteException;
use Nyoncode\BackupManager\ValueObjects\ConfigOption;

/**
 * Applies a set of configuration changes to the application, safely.
 *
 * Two channels, chosen per key rather than per call: a value the package reads
 * through `env()` is written to `.env`, everything else is edited into
 * `config/backup-manager.php`. Routing matters — replacing an `env('…')` call
 * with a literal would silently detach the setting from the environment it is
 * deployed with, so the writer moves that change to `.env` instead of doing it.
 *
 * Every key is checked against {@see ConfigReference} before anything is
 * written: an unknown key, or a value of the wrong type, aborts the whole call
 * rather than applying half of it.
 */
final class ConfigWriter
{
    public function __construct(
        private readonly ConfigReference $reference,
        private readonly PhpConfigFile $file,
        private readonly EnvFile $env,
        private readonly string $stubPath,
        private readonly string $backupDirectory,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Dot paths below `backup-manager.`.
     * @param  array<string, mixed>  $env  Environment variables of this package.
     * @return array{
     *     applied: bool,
     *     changes: list<array{channel: string, key: string, from: string|null, to: string}>,
     *     notes: list<string>,
     *     files: array<string, string|null>,
     * }
     */
    public function apply(array $config, array $env, bool $dryRun): array
    {
        $notes = [];
        $envValues = [];

        foreach ($env as $variable => $value) {
            $option = $this->reference->findByEnv((string) $variable)
                ?? throw ConfigWriteException::unknownEnvironmentVariable((string) $variable);

            $this->assertAcceptable($option, $option->key, $value);
            $envValues[(string) $variable] = $this->toEnvString($value);
        }

        $configValues = [];

        foreach ($config as $path => $value) {
            $path = (string) $path;
            $option = $this->reference->find($path)
                ?? throw ConfigWriteException::unknownKey($path, $this->reference->suggest($path));

            $this->assertAcceptable($option, $path, $value);

            // Prefer .env whenever the key is already wired to an environment
            // variable in the file we would otherwise rewrite.
            if ($option->env !== null && $this->env->exists() && $this->readsEnv($path)) {
                $envValues[$option->env] = $this->toEnvString($value);
                $notes[] = "[{$path}] is read from the environment, so the change was written to {$option->env} in .env instead.";

                continue;
            }

            $configValues[$path] = $value;
        }

        $changes = [];

        foreach ($this->env->set($envValues, $dryRun) as $change) {
            $option = $this->reference->findByEnv($change['key']);
            $changes[] = [
                'channel' => 'env',
                'key' => $change['key'],
                'from' => $this->mask($option, $change['from']),
                'to' => (string) $this->mask($option, $change['to']),
            ];
        }

        $configFile = $this->file;

        if ($configValues !== [] && ! $configFile->exists()) {
            if ($dryRun) {
                $notes[] = 'config/backup-manager.php is not published yet; it would be published from the package before editing.';
                $configFile = new PhpConfigFile($this->stubPath);
            } else {
                $this->publish();
                $notes[] = 'Published config/backup-manager.php from the package defaults.';
            }
        }

        $backup = null;

        if ($configValues !== []) {
            $edit = $configFile->edit($configValues);

            foreach ($edit['changes'] as $change) {
                $option = $this->reference->find($change['key']);
                $changes[] = [
                    'channel' => 'config',
                    'key' => $change['key'],
                    'from' => $this->mask($option, $change['from']),
                    'to' => (string) $this->mask($option, $change['to']),
                ];
            }

            if (! $dryRun && $edit['changes'] !== []) {
                $backup = $this->file->write($edit['source'], $this->backupDirectory);
            }
        }

        return [
            'applied' => ! $dryRun && $changes !== [],
            'changes' => $changes,
            'notes' => $notes,
            'files' => [
                'config' => $this->file->path(),
                'env' => $this->env->path(),
                'config_backup' => $backup,
            ],
        ];
    }

    /**
     * Copy the package's config file into the application.
     */
    public function publish(): void
    {
        if ($this->file->exists()) {
            return;
        }

        $directory = dirname($this->file->path());

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw ConfigWriteException::unwritable($directory);
        }

        if (! @copy($this->stubPath, $this->file->path())) {
            throw ConfigWriteException::unwritable($this->file->path());
        }
    }

    /**
     * Whether the published file currently resolves this key through `env()`.
     */
    private function readsEnv(string $path): bool
    {
        if (! $this->file->exists()) {
            // Nothing published yet: the package default is whatever the stub
            // says, and the stub is where the env() calls live.
            return str_contains((string) (new PhpConfigFile($this->stubPath))->sourceOf($path), 'env(');
        }

        return str_contains((string) $this->file->sourceOf($path), 'env(');
    }

    /** Whether a security setting is in force: a cipher other than `none`, or true. */
    private static function isOn(mixed $value): bool
    {
        if (is_string($value)) {
            return ! in_array(strtolower(trim($value)), ['', 'none', 'false', '0', 'off'], true);
        }

        return (bool) $value;
    }

    private function assertAcceptable(ConfigOption $option, string $path, mixed $value): void
    {
        // Checked before the value, and never skipped: `force` overrides the
        // validator's verdict on the result, not who may change what.
        if ($option->guarded) {
            throw ConfigWriteException::guardedKey($path);
        }

        $current = config('backup-manager.'.$path);

        // "Unset" means no key is in effect — a key that falls back to another
        // is already in use when that one is set, and setting it would swap
        // the key backups are signed with.
        $effective = (is_string($current) && trim($current) !== '') || $option->fallsBackTo === null
            ? $current
            : config('backup-manager.'.$option->fallsBackTo);

        if ($option->setOnce && is_string($effective) && trim($effective) !== '' && $value !== $effective) {
            throw ConfigWriteException::alreadySet($path);
        }

        if ($option->noDowngrade && self::isOn($current) && ! self::isOn($value)) {
            throw ConfigWriteException::downgrade($path);
        }

        if ($option->accepts($value)) {
            return;
        }

        if ($option->allowed !== [] && is_string($value)) {
            throw ConfigWriteException::notAllowedValue($path, $value, implode(', ', $option->allowed));
        }

        throw ConfigWriteException::invalidValue($path, $option->type, $value);
    }

    private function toEnvString(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => implode(',', array_map('strval', $value)),
            default => (string) $value,
        };
    }

    /**
     * A credential never travels back out of this package in clear text.
     */
    private function mask(?ConfigOption $option, ?string $value): ?string
    {
        if ($value === null || $option === null || ! $option->secret) {
            return $value;
        }

        return $value === '' ? '' : '********';
    }
}
