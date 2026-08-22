<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Database\Restorers;

use Nyoncode\BackupManager\Concerns\RunsProcesses;
use Nyoncode\BackupManager\Contracts\Database\DatabaseRestorer;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Exceptions\RestoreException;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Restores MySQL/MariaDB databases by piping an SQL dump into the server's own
 * client — `mysql`, or MariaDB's `mariadb`.
 */
final class MySqlRestorer implements DatabaseRestorer
{
    use RunsProcesses;

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly ?int $timeout = null,
    ) {}

    public function restore(DatabaseTarget $target, string $dumpPath): void
    {
        $binary = $this->binaries->locateFirst($target->driver->clientBinaries());
        $environment = $target->password !== null ? ['MYSQL_PWD' => $target->password] : [];

        $command = [$binary];

        if ($target->host !== null) {
            $command[] = "--host={$target->host}";
        }

        if ($target->port !== null) {
            $command[] = "--port={$target->port}";
        }

        if ($target->username !== null) {
            $command[] = "--user={$target->username}";
        }

        $command[] = $target->database;

        try {
            $this->runProcess(
                command: $command,
                timeout: $this->timeout,
                environment: $environment,
                inputFile: $dumpPath,
            );
        } catch (ProcessFailedException $exception) {
            throw RestoreException::failed(
                $exception->getProcess()->getErrorOutput() ?: $exception->getMessage(),
                $exception,
            );
        }
    }
}
