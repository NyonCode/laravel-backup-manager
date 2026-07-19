<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\DTOs;

use Nyoncode\BackupManager\Enums\BackupMode;
use Nyoncode\BackupManager\Enums\BackupType;

/**
 * A fully resolved, immutable description of a single backup profile.
 *
 * Produced by the profile resolver from configuration; every downstream stage
 * reads from this rather than touching config directly.
 */
final readonly class BackupDefinition
{
    /**
     * @param  list<DatabaseTarget>  $databases
     * @param  list<string>  $destinations  Laravel filesystem disk names.
     * @param  array<string, mixed>|null  $retention  Profile retention overrides, or null for defaults.
     */
    public function __construct(
        public string $profile,
        public string $name,
        public BackupType $type,
        public array $databases,
        public FileSource $files,
        public array $destinations,
        public string $path,
        public ?array $retention = null,
        public BackupMode $mode = BackupMode::Archive,
    ) {}
}
