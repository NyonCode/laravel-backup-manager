<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Events;

use Nyoncode\BackupManager\ValueObjects\BackupResult;

/**
 * Dispatched after a backup has been successfully stored on all destinations.
 */
final readonly class BackupCompleted
{
    public function __construct(
        public BackupResult $result,
    ) {}
}
