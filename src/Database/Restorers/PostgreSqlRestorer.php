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
 * Restores PostgreSQL databases by piping an SQL dump into the `psql` client.
 */
final class PostgreSqlRestorer implements DatabaseRestorer, LocksDownClient
{
    use RunsProcesses;

    /** @var array<string, bool> Whether each psql binary understands \restrict. */
    private static array $restricts = [];

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly ?int $timeout = null,
    ) {}

    public function restore(DatabaseTarget $target, string $dumpPath): void
    {
        // Before anything reaches psql: see SqlDumpInspector.
        SqlDumpInspector::assertSafeForPsql($dumpPath);

        $binary = $this->binaries->locateFirst($target->driver->clientBinaries());
        $environment = $target->password !== null ? ['PGPASSWORD' => $target->password] : [];

        $command = [$binary, '--set', 'ON_ERROR_STOP=on'];

        if ($target->host !== null) {
            $command[] = "--host={$target->host}";
        }

        if ($target->port !== null) {
            $command[] = "--port={$target->port}";
        }

        if ($target->username !== null) {
            $command[] = "--username={$target->username}";
        }

        $command[] = $target->database;

        // Where psql has it, the dump runs inside a `\restrict` of our own: every
        // meta-command is refused until the matching `\unrestrict`, whose key
        // only we know. The dump's own (un)restrict lines — pg_dump 17.6+ writes
        // them — are dropped, since a second \restrict would fail.
        $input = $this->restricts($binary) ? $this->restricted($dumpPath) : $dumpPath;

        try {
            $this->runProcess(
                command: $command,
                timeout: $this->timeout,
                environment: $environment,
                inputFile: $input,
            );
        } catch (ProcessFailedException $exception) {
            throw RestoreException::failed(
                $exception->getProcess()->getErrorOutput() ?: $exception->getMessage(),
                $exception,
            );
        } finally {
            if ($input !== $dumpPath && is_file($input)) {
                @unlink($input);
            }
        }
    }

    public function locksDownClient(DatabaseDriver $driver): bool
    {
        try {
            return $this->restricts($this->binaries->locateFirst($driver->clientBinaries()));
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * psql gained `\restrict` in 18 and in the 17.6, 16.10, 15.14, 14.19 and
     * 13.22 point releases (CVE-2025-8714).
     */
    private function restricts(string $binary): bool
    {
        if (array_key_exists($binary, self::$restricts)) {
            return self::$restricts[$binary];
        }

        try {
            $process = new Process([$binary, '--version'], timeout: 10);
            $process->run();
            $version = $process->getOutput();
        } catch (Throwable) {
            $version = '';
        }

        $supported = false;

        if (preg_match('/(\d+)\.(\d+)/', $version, $m) === 1) {
            $minimum = [13 => 22, 14 => 19, 15 => 14, 16 => 10, 17 => 6];
            $major = (int) $m[1];
            $minor = (int) $m[2];
            $supported = $major >= 18 || (isset($minimum[$major]) && $minor >= $minimum[$major]);
        }

        return self::$restricts[$binary] = $supported;
    }

    /**
     * A copy of the dump wrapped in a `\restrict` with a key of our own.
     */
    private function restricted(string $dumpPath): string
    {
        $key = bin2hex(random_bytes(16));
        $wrapped = $dumpPath.'.restricted';
        $in = fopen($dumpPath, 'rb');
        $out = fopen($wrapped, 'wb');

        if ($in === false || $out === false) {
            throw RestoreException::failed("Unable to prepare [{$dumpPath}] for a restricted restore.");
        }

        fwrite($out, "\\restrict {$key}\n");

        while (($line = fgets($in)) !== false) {
            if (preg_match('/^\\\\(?:un)?restrict\s/', $line) !== 1) {
                fwrite($out, $line);
            }
        }

        fwrite($out, "\n\\unrestrict {$key}\n");
        fclose($in);
        fclose($out);

        return $wrapped;
    }
}
