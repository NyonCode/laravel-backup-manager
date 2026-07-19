<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Compression;

use Nyoncode\BackupManager\Concerns\RunsProcesses;
use Nyoncode\BackupManager\Contracts\Compression\Compressor;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Symfony\Component\Process\Exception\ProcessFailedException;

/**
 * Zstandard compressor delegating to the `zstd` binary.
 *
 * Zstd offers the best speed/ratio balance but is not bundled with PHP, so it
 * is driven as an external process.
 */
final class ZstdCompressor implements Compressor
{
    use RunsProcesses;

    public function __construct(
        private readonly BinaryLocator $binaries,
        private readonly int $level = 3,
        private readonly ?int $timeout = null,
    ) {}

    public function compress(string $source, string $destination): void
    {
        $binary = $this->binaries->locate('zstd');

        $this->run([
            $binary, '-q', '-f', '-'.max(1, min(19, $this->level)),
            '-o', $destination, $source,
        ]);
    }

    public function decompress(string $source, string $destination): void
    {
        $binary = $this->binaries->locate('zstd');

        $this->run([$binary, '-q', '-f', '-d', '-o', $destination, $source]);
    }

    public function extension(): string
    {
        return 'zst';
    }

    /**
     * @param  list<string>  $command
     */
    private function run(array $command): void
    {
        try {
            $this->runProcess($command, timeout: $this->timeout);
        } catch (ProcessFailedException $exception) {
            throw new BackupManagerException(
                'Zstd compression failed: '.($exception->getProcess()->getErrorOutput() ?: $exception->getMessage()),
                previous: $exception,
            );
        }
    }
}
