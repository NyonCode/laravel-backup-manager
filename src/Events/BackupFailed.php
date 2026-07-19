<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Events;

use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Throwable;

/**
 * Dispatched when a backup run throws before completion.
 */
final readonly class BackupFailed
{
    public function __construct(
        public BackupDefinition $definition,
        public Throwable $exception,
    ) {}
}
