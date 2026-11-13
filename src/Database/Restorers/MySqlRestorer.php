<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Database\Restorers;

use Nyoncode\BackupManager\Concerns\RunsProcesses;
use Nyoncode\BackupManager\Contracts\Database\DatabaseRestorer;
use Nyoncode\BackupManager\Contracts\Database\LocksDownClient;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Exceptions\RestoreException;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Nyoncode\BackupManager\Support\SqlDumpInspector;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Restores MySQL/MariaDB databases by piping an SQL dump into the server's own
 * client — `mysql`, or MariaDB's `mariadb`.
 */
final class MySqlRestorer implements DatabaseRestorer, LocksDownClient
{
    use RunsProcesses;

    /** @var array<string, list<string>> Sandbox flags per client binary. */
    private static array $sandbox = [];

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly ?int $timeout = null,
    ) {}

    public function restore(DatabaseTarget $target, string $dumpPath): void
    {
        // Before anything reaches the client: see SqlDumpInspector.
        SqlDumpInspector::assertSafeForMysql($dumpPath);

        $binary = $this->binaries->locateFirst($target->driver->clientBinaries());
        $environment = $target->password !== null ? ['MYSQL_PWD' => $target->password] : [];

        $command = [$binary, ...$this->sandboxFlags($binary)];

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

    /**
     * The client's own lock on file-system and shell commands, where it has one:
     * MariaDB's `--sandbox` (10.5.25 / 10.6.18 / 10.11.8 / 11.x, 2024) or MySQL's
     * `--system-command=OFF` (8.0.40+). Asked of `--help` rather than guessed from
     * a version string, and a second line of defence after the inspection —
     * older clients simply run without it.
     *
     * @return list<string>
     */
    private function sandboxFlags(string $binary): array
    {
        if (array_key_exists($binary, self::$sandbox)) {
            return self::$sandbox[$binary];
        }

        try {
            $process = new Process([$binary, '--help'], timeout: 10);
            $process->run();
            $help = $process->getOutput();
        } catch (Throwable) {
            $help = '';
        }

        return self::$sandbox[$binary] = match (true) {
            str_contains($help, '--sandbox') => ['--sandbox'],
            str_contains($help, '--commands') => ['--commands=OFF'],
            str_contains($help, '--system-command') => ['--system-command=OFF'],
            default => [],
        };
    }

    /**
     * Locked down means every client command is off: MariaDB's `--sandbox`
     * or MySQL's `--commands=OFF` (8.4.6+). `--system-command=OFF` alone still
     * leaves `source` and `tee`, so it is applied but does not count.
     */
    public function locksDownClient(DatabaseDriver $driver): bool
    {
        try {
            $binary = $this->binaries->locateFirst($driver->clientBinaries());
        } catch (Throwable) {
            return false;
        }

        return array_intersect($this->sandboxFlags($binary), ['--sandbox', '--commands=OFF']) !== [];
    }
}
