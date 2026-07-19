<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Database\Dumpers;

use Nyoncode\BackupManager\Concerns\RunsProcesses;
use Nyoncode\BackupManager\Contracts\Database\DatabaseDumper;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Dumps PostgreSQL databases via the `pg_dump` binary.
 *
 * The password is provided via the PGPASSWORD environment variable.
 */
final class PostgreSqlDumper implements DatabaseDumper
{
    use RunsProcesses;

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly ?int $timeout = null,
    ) {}

    public function dump(DatabaseTarget $target, string $outputPath): void
    {
        $binary = $this->binaries->locate('pg_dump');
        $environment = $target->password !== null ? ['PGPASSWORD' => $target->password] : [];

        try {
            $this->runProcess(
                command: $this->command($binary, $target),
                timeout: $this->timeout,
                environment: $environment,
                outputFile: $outputPath,
            );
        } catch (ProcessFailedException $exception) {
            throw DatabaseDumpException::processFailed(
                $target->connection,
                $exception->getProcess()->getErrorOutput() ?: $exception->getMessage(),
                $exception,
            );
        }

        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw DatabaseDumpException::emptyDump($target->connection);
        }
    }

    /**
     * @return list<string>
     */
    private function command(string $binary, DatabaseTarget $target): array
    {
        $command = [$binary, '--no-owner', '--no-privileges', '--clean', '--if-exists'];

        if ($target->host !== null) {
            $command[] = "--host={$target->host}";
        }

        if ($target->port !== null) {
            $command[] = "--port={$target->port}";
        }

        if ($target->username !== null) {
            $command[] = "--username={$target->username}";
        }

        foreach ($target->excludeTables as $table) {
            $command[] = "--exclude-table={$table}";
        }

        foreach ($target->schemaOnlyTables as $table) {
            $command[] = "--exclude-table-data={$table}";
        }

        $command[] = $target->database;

        return $command;
    }
}
