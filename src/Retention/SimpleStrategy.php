<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Retention;

use Nyoncode\BackupManager\Contracts\Retention\RetentionStrategy;

/**
 * Keeps the N newest backups and discards the rest.
 */
final class SimpleStrategy implements RetentionStrategy
{
    public function __construct(
        private readonly int $keepLatest,
    ) {}

    public function keep(array $backups): array
    {
        return array_slice($backups, 0, max(0, $this->keepLatest));
    }
}
