<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Concerns;

use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Reusable helper for running external processes with a bounded timeout.
 *
 * Keeps the process plumbing out of the dumpers/restorers so they only
 * describe *what* command to run, not *how* to run it.
 */
trait RunsProcesses
{
    /**
     * Run a command, streaming stdout to an output file when given.
     *
     * @param  list<string>  $command
     * @param  array<string, string>  $environment  Extra environment variables (e.g. credentials).
     *
     * @throws ProcessFailedException When the process exits non-zero.
     */
    protected function runProcess(
        array $command,
        ?int $timeout = null,
        array $environment = [],
        ?string $outputFile = null,
        bool $append = false,
        ?string $inputFile = null,
    ): void {
        $process = new Process($command, timeout: $timeout, env: $environment);

        if ($inputFile !== null) {
            $process->setInput($this->openStream($inputFile, 'rb'));
        }

        if ($outputFile !== null) {
            $handle = $this->openStream($outputFile, $append ? 'ab' : 'wb');

            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });

            fclose($handle);
        } else {
            $process->run();
        }

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }
    }

    /**
     * Open a file stream, failing loudly instead of returning false.
     *
     * @return resource
     */
    private function openStream(string $path, string $mode)
    {
        $handle = fopen($path, $mode);

        if ($handle === false) {
            throw new BackupManagerException("Unable to open stream for [{$path}].");
        }

        return $handle;
    }
}
