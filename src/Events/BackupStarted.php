<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Events;

use Nyoncode\BackupManager\DTOs\BackupDefinition;

/**
 * Dispatched immediately before a backup run begins.
 */
final readonly class BackupStarted
{
    public function __construct(
        public BackupDefinition $definition,
    ) {}
}
