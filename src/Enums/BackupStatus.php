<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * Lifecycle status of a backup operation.
 */
enum BackupStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return $this === self::Completed || $this === self::Failed;
    }

    public function isSuccessful(): bool
    {
        return $this === self::Completed;
    }
}
