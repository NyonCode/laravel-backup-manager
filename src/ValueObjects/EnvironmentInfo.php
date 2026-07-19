<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * A snapshot of the runtime environment at the moment a backup was created.
 *
 * Captured into the manifest so a restore target can be validated against
 * the versions the backup was produced with.
 */
final readonly class EnvironmentInfo
{
    public function __construct(
        public string $appName,
        public ?string $appVersion,
        public string $laravelVersion,
        public string $phpVersion,
        public string $environment,
        public string $operatingSystem,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            appName: $data['app_name'],
            appVersion: $data['app_version'] ?? null,
            laravelVersion: $data['laravel_version'],
            phpVersion: $data['php_version'],
            environment: $data['environment'],
            operatingSystem: $data['operating_system'],
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'app_name' => $this->appName,
            'app_version' => $this->appVersion,
            'laravel_version' => $this->laravelVersion,
            'php_version' => $this->phpVersion,
            'environment' => $this->environment,
            'operating_system' => $this->operatingSystem,
        ];
    }
}
