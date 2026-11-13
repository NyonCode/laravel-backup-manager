<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Database\Dumpers;

use Nyoncode\BackupManager\Contracts\Database\DatabaseDumper;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;

/**
 * "Dumps" an SQLite database into a single, self-contained database file.
 *
 * `VACUUM INTO` where available (pdo_sqlite, SQLite 3.27+): a consistent
 * snapshot that includes committed pages still in the write-ahead log. A plain
 * file copy is the fallback, and is refused for a WAL database with pending
 * changes it would miss.
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

        // VACUUM INTO writes a transactionally consistent copy, committed
        // pages from the -wal file included. Copying the main file alone misses
        // whatever is still in the WAL — on a busy WAL-mode database, the most
        // recent writes — and can catch a page half-written.
        if ($this->vacuumInto($source, $outputPath)) {
            return;
        }

        if (is_file($source.'-wal') && (int) filesize($source.'-wal') > 0) {
            throw DatabaseDumpException::processFailed(
                $target->connection,
                "SQLite database [{$source}] is in WAL mode with uncheckpointed changes, and VACUUM INTO is unavailable (pdo_sqlite missing or SQLite older than 3.27); a plain file copy would miss them.",
            );
        }

        if (! copy($source, $outputPath)) {
            throw DatabaseDumpException::processFailed(
                $target->connection,
                "Failed to copy SQLite database file from [{$source}].",
            );
        }
    }

    private function vacuumInto(string $source, string $outputPath): bool
    {
        if (! extension_loaded('pdo_sqlite')) {
            return false;
        }

        try {
            if (is_file($outputPath)) {
                unlink($outputPath); // VACUUM INTO refuses an existing file
            }
            $pdo = new \PDO('sqlite:'.$source, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $statement = $pdo->prepare('VACUUM INTO ?');
            $statement->execute([$outputPath]);

            return is_file($outputPath);
        } catch (\Throwable) {
            if (is_file($outputPath)) {
                unlink($outputPath);
            }

            return false;
        }
    }
}
