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
 * Dumps MySQL and MariaDB databases via the `mysqldump` binary.
 *
 * The password is passed through the environment (MYSQL_PWD) rather than the
 * command line so it never appears in the host's process list.
 */
final class MySqlDumper implements DatabaseDumper
{
    use RunsProcesses;

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly ?int $timeout = null,
    ) {}

    public function dump(DatabaseTarget $target, string $outputPath): void
    {
        $binary = $this->binaries->locate('mysqldump');
        $environment = $target->password !== null ? ['MYSQL_PWD' => $target->password] : [];

        $ignored = [...$target->excludeTables, ...$target->schemaOnlyTables];

        try {
            $this->runProcess(
                command: $this->baseCommand($binary, $target, $ignored),
                timeout: $this->timeout,
                environment: $environment,
                outputFile: $outputPath,
            );

            if ($target->schemaOnlyTables !== []) {
                $this->runProcess(
                    command: $this->schemaOnlyCommand($binary, $target),
                    timeout: $this->timeout,
                    environment: $environment,
                    outputFile: $outputPath,
                    append: true,
                );
            }
        } catch (ProcessFailedException $exception) {
            throw DatabaseDumpException::processFailed(
                $target->connection,
                $exception->getProcess()->getErrorOutput() ?: $exception->getMessage(),
                $exception,
            );
        }

        $this->assertNotEmpty($target, $outputPath);
    }

    /**
     * @param  list<string>  $ignoredTables
     * @return list<string>
     */
    private function baseCommand(string $binary, DatabaseTarget $target, array $ignoredTables): array
    {
        $command = [
            $binary,
            ...$this->connectionFlags($target),
            '--single-transaction',
            '--skip-lock-tables',
            '--routines',
            '--triggers',
            '--events',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
        ];

        foreach ($ignoredTables as $table) {
            $command[] = "--ignore-table={$target->database}.{$table}";
        }

        $command[] = $target->database;

        return $command;
    }

    /**
     * @return list<string>
     */
    private function schemaOnlyCommand(string $binary, DatabaseTarget $target): array
    {
        // Keep the default --add-drop-table so a restore of the structure is
        // idempotent (DROP TABLE IF EXISTS before CREATE TABLE).
        return [
            $binary,
            ...$this->connectionFlags($target),
            '--no-data',
            '--no-tablespaces',
            $target->database,
            ...$target->schemaOnlyTables,
        ];
    }

    /**
     * @return list<string>
     */
    private function connectionFlags(DatabaseTarget $target): array
    {
        $flags = [];

        if ($target->host !== null) {
            $flags[] = "--host={$target->host}";
        }

        if ($target->port !== null) {
            $flags[] = "--port={$target->port}";
        }

        if ($target->username !== null) {
            $flags[] = "--user={$target->username}";
        }

        return $flags;
    }

    private function assertNotEmpty(DatabaseTarget $target, string $outputPath): void
    {
        if (! is_file($outputPath) || filesize($outputPath) === 0) {
            throw DatabaseDumpException::emptyDump($target->connection);
        }
    }
}
