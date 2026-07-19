<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Database\Dumpers;

use Nyoncode\BackupManager\Contracts\Database\DatabaseDumper;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;

/**
 * "Dumps" an SQLite database by copying its single data file.
 *
 * This is the most reliable way to snapshot SQLite: a byte-for-byte copy
 * preserves the schema, data and any driver-specific pragmas.
 */
final class SqliteDumper implements DatabaseDumper
{
    public function dump(DatabaseTarget $target, string $outputPath): void
    {
        $source = $target->database;

        if ($source === ':memory:' || ! is_file($source)) {
            throw DatabaseDumpException::processFailed(
                $target->connection,
                "SQLite database file [{$source}] does not exist or is in-memory.",
            );
        }

        if (! copy($source, $outputPath)) {
            throw DatabaseDumpException::processFailed(
                $target->connection,
                "Failed to copy SQLite database file from [{$source}].",
            );
        }
    }
}
