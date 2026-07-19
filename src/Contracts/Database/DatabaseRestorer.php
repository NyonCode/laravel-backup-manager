<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Database;

use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\RestoreException;

/**
 * Capability: restore a single database from a dump file.
 */
interface DatabaseRestorer
{
    /**
     * Restore the target database from the dump located at the given path.
     *
     * @throws RestoreException
     */
    public function restore(DatabaseTarget $target, string $dumpPath): void;
}
