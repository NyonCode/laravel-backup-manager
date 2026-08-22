<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * One documented configuration key of the package.
 *
 * Together these form a machine-readable description of `config/backup-manager.php`
 * — the reference an assistant reads before it proposes a change, and the guard
 * that rejects an unknown key or an impossible value before anything is written.
 */
final readonly class ConfigOption
{
    /**
     * @param  string  $key  Dot path below `backup-manager.`; `*` stands for a profile name.
     * @param  string  $type  `string`, `integer`, `boolean`, `string[]`, `array`; a trailing `?` allows null.
     * @param  list<string>  $allowed  Permitted values, when the key is an enumeration.
     * @param  bool  $secret  A credential: never echoed back through the protocol.
     */
    public function __construct(
        public string $key,
        public string $type,
        public string $description,
        public ?string $env = null,
        public array $allowed = [],
        public mixed $default = null,
        public bool $requiresPro = false,
        public bool $secret = false,
    ) {}

    /**
     * Whether a concrete config path (`profiles.default.type`) is this option.
     */
    public function matches(string $path): bool
    {
        if (! str_contains($this->key, '*')) {
            return $this->key === $path;
        }

        $pattern = '/^'.str_replace('\*', '[^.]+', preg_quote($this->key, '/')).'$/';

        return preg_match($pattern, $path) === 1;
    }

    /**
     * Whether a value is of the declared type and, for an enumeration, allowed.
     */
    public function accepts(mixed $value): bool
    {
        $nullable = str_ends_with($this->type, '?');
        $type = $nullable ? substr($this->type, 0, -1) : $this->type;

        if ($value === null) {
            return $nullable;
        }

        $typed = match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'boolean' => is_bool($value),
            'string[]' => is_array($value) && array_is_list($value) && array_filter($value, 'is_string') === $value,
            'array' => is_array($value),
            default => true,
        };

        if (! $typed) {
            return false;
        }

        return $this->allowed === [] || (is_string($value) && in_array($value, $this->allowed, true));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'type' => $this->type,
            'description' => $this->description,
            'env' => $this->env,
            'allowed' => $this->allowed === [] ? null : $this->allowed,
            'default' => $this->secret ? null : $this->default,
            'requires_pro' => $this->requiresPro ?: null,
            'secret' => $this->secret ?: null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
