<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Retention;

use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Capability: decide which backups to keep from a set.
 */
interface RetentionStrategy
{
    /**
     * @param  list<StoredBackup>  $backups  Newest first.
     * @return list<StoredBackup> The subset to retain.
     */
    public function keep(array $backups): array;
}
