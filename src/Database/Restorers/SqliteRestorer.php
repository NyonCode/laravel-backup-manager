<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Database\Restorers;

use Nyoncode\BackupManager\Contracts\Database\DatabaseRestorer;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\RestoreException;

/**
 * Restores an SQLite database by copying the dumped file back into place.
 */
final class SqliteRestorer implements DatabaseRestorer
{
    public function restore(DatabaseTarget $target, string $dumpPath): void
    {
        if (! is_file($dumpPath)) {
            throw RestoreException::failed("SQLite dump file [{$dumpPath}] does not exist.");
        }

        $destination = $target->database;
        $directory = dirname($destination);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw RestoreException::failed("Unable to create directory [{$directory}] for SQLite restore.");
        }

        if (! copy($dumpPath, $destination)) {
            throw RestoreException::failed("Failed to copy SQLite database file to [{$destination}].");
        }
    }
}
