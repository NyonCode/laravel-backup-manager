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

        // Copied beside the target, then swapped in with a rename, so a crash
        // never leaves a half-written database at the live path.
        $temporary = $destination.'.bm-restore-tmp';

        if (! copy($dumpPath, $temporary)) {
            @unlink($temporary);

            throw RestoreException::failed("Failed to copy SQLite database file to [{$destination}].");
        }

        // The swap creates a new file, owned by whoever runs the restore. Give it
        // the live database's mode and (when we may) owner, or a restore run as
        // root leaves the app with "attempt to write a readonly database".
        if (is_file($destination)) {
            $perms = @fileperms($destination);

            if (is_int($perms)) {
                @chmod($temporary, $perms & 0777);
            }

            if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
                $owner = @fileowner($destination);
                $group = @filegroup($destination);

                if (is_int($owner)) {
                    @chown($temporary, $owner);
                }

                if (is_int($group)) {
                    @chgrp($temporary, $group);
                }
            }
        }

        // Only once the swap can happen: the journals below belong to the live
        // database until then, and losing them without the rename would drop
        // its un-checkpointed transactions.
        if (! is_writable($directory)) {
            @unlink($temporary);

            throw RestoreException::failed("Cannot replace the SQLite database: [{$directory}] is not writable.");
        }

        // A -wal/-shm pair left from the database being replaced belongs to
        // the old file: SQLite would replay that journal over the restored
        // one on the next open and corrupt it.
        foreach (['-wal', '-shm', '-journal'] as $suffix) {
            if (is_file($destination.$suffix)) {
                @unlink($destination.$suffix);
            }
        }

        if (! rename($temporary, $destination)) {
            @unlink($temporary);

            throw RestoreException::failed("Failed to move the restored SQLite database into [{$destination}].");
        }
    }
}
