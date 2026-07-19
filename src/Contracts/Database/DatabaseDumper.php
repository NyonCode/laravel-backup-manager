<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Database;

use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;

/**
 * Capability: dump a single database to a file on disk.
 */
interface DatabaseDumper
{
    /**
     * Write a dump of the target database to the given output path.
     *
     * @throws DatabaseDumpException
     */
    public function dump(DatabaseTarget $target, string $outputPath): void;
}
