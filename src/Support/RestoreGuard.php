<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Managers\DatabaseManager;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Provides restore atomicity through a snapshot-and-compensate (saga) strategy.
 *
 * Before a restore overwrites anything, {@see capture()} records the current
 * state: a dump of each affected database and a copy of every file that will be
 * replaced. If the restore then fails, {@see rollback()} reinstates that state,
 * returning the environment to how it was — the safety net for a failed update.
 */
final class RestoreGuard
{
    public function __construct(
        private readonly DatabaseManager $databases,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Capture the current state that the restore is about to overwrite.
     *
     * @param  callable(string): ?DatabaseTarget  $resolveTarget
     */
    public function capture(
        Manifest $manifest,
        RestoreOptions $options,
        string $rollbackDir,
        callable $resolveTarget,
    ): RestorePoint {
        $point = new RestorePoint;

        if (! is_dir($rollbackDir)) {
            mkdir($rollbackDir, 0755, true);
        }

        if ($options->restoreDatabases) {
            $this->captureDatabases($manifest, $rollbackDir, $resolveTarget, $point);
        }

        if ($options->restoreFiles) {
            $this->captureFiles($manifest, $options, $rollbackDir, $point);
        }

        return $point;
    }

    /**
     * Reinstate the captured state after a failed restore.
     */
    public function rollback(RestorePoint $point): void
    {
        foreach ($point->databases as $snapshot) {
            $this->safely(fn () => $this->databases
                ->restorer($snapshot['target']->driver)
                ->restore($snapshot['target'], $snapshot['path']));
        }

        foreach ($point->files as $absolute => $copy) {
            $this->safely(function () use ($absolute, $copy): void {
                $directory = dirname($absolute);

                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                copy($copy, $absolute);
            });
        }

        foreach ($point->createdFiles as $absolute) {
            $this->safely(function () use ($absolute): void {
                if (is_file($absolute)) {
                    unlink($absolute);
                }
            });
        }
    }

    /**
     * @param  callable(string): ?DatabaseTarget  $resolveTarget
     */
    private function captureDatabases(
        Manifest $manifest,
        string $rollbackDir,
        callable $resolveTarget,
        RestorePoint $point,
    ): void {
        foreach ($manifest->databases as $artifact) {
            $target = $resolveTarget($artifact->connection);

            if ($target === null) {
                continue;
            }

            $path = $rollbackDir.'/db-'.$artifact->connection.'.'.$target->driver->dumpExtension();

            // If the current database can't be dumped (e.g. it does not exist
            // yet), there is nothing to roll back to — skip it.
            try {
                $this->databases->dumper($target->driver)->dump($target, $path);
                $point->databases[$artifact->connection] = ['path' => $path, 'target' => $target];
            } catch (Throwable $exception) {
                $this->logger->warning('[backup-manager] Could not snapshot database for rollback', [
                    'connection' => $artifact->connection,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function captureFiles(
        Manifest $manifest,
        RestoreOptions $options,
        string $rollbackDir,
        RestorePoint $point,
    ): void {
        $filesDir = $rollbackDir.'/files';

        foreach ($manifest->files as $file) {
            if ($file->isSymlink || ! ArchivePath::isFileEntry($file->path)) {
                continue;
            }

            $absolute = ArchivePath::toAbsolute($file->path);

            if ($options->isSelective() && ! $this->matchesSelection($absolute, $options->onlyPaths)) {
                continue;
            }

            if (! is_file($absolute)) {
                $point->createdFiles[] = $absolute;

                continue;
            }

            $copy = $filesDir.'/'.md5($absolute);

            if (! is_dir($filesDir)) {
                mkdir($filesDir, 0755, true);
            }

            if (copy($absolute, $copy)) {
                $point->files[$absolute] = $copy;
            }
        }
    }

    /**
     * @param  list<string>  $selection
     */
    private function matchesSelection(string $absolute, array $selection): bool
    {
        foreach ($selection as $prefix) {
            $normalized = rtrim($prefix, '/');

            if ($absolute === $normalized || str_starts_with($absolute, $normalized.'/')) {
                return true;
            }
        }

        return false;
    }

    private function safely(callable $action): void
    {
        try {
            $action();
        } catch (Throwable $exception) {
            $this->logger->error('[backup-manager] Rollback step failed', ['error' => $exception->getMessage()]);
        }
    }
}
