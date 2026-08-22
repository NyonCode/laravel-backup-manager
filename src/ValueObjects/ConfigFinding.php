<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

use Nyoncode\BackupManager\Enums\ConfigSeverity;

/**
 * One problem (or observation) about the current configuration.
 *
 * Every finding names the key it is about and how to fix it, so a report is
 * directly actionable — by a person reading it or by an assistant turning it
 * into a configuration change.
 */
final readonly class ConfigFinding
{
    public function __construct(
        public ConfigSeverity $severity,
        public string $key,
        public string $message,
        public ?string $fix = null,
    ) {}

    public static function error(string $key, string $message, ?string $fix = null): self
    {
        return new self(ConfigSeverity::Error, $key, $message, $fix);
    }

    public static function warning(string $key, string $message, ?string $fix = null): self
    {
        return new self(ConfigSeverity::Warning, $key, $message, $fix);
    }

    public static function info(string $key, string $message, ?string $fix = null): self
    {
        return new self(ConfigSeverity::Info, $key, $message, $fix);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return array_filter([
            'severity' => $this->severity->value,
            'key' => $this->key,
            'message' => $this->message,
            'fix' => $this->fix,
        ], static fn (?string $value): bool => $value !== null);
    }
}
