<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Exceptions\RestoreException;
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
    /** @var list<string> Failures of the rollback in progress. */
    private array $failures = [];

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
    /**
     * Put everything back. Every step is attempted even when one fails; the
     * failures are returned, so the caller never reports a rollback as complete
     * when part of it was not.
     *
     * @return list<string> What could not be put back.
     */
    public function rollback(RestorePoint $point): array
    {
        $this->failures = [];

        foreach ($point->databases as $snapshot) {
            $this->safely(fn () => $this->databases
                ->restorer($snapshot['target']->driver)
                ->restore($snapshot['target'], $snapshot['path']));
        }

        foreach ($point->files as $absolute => $copy) {
            $mode = $point->modes[$absolute] ?? null;

            $this->safely(function () use ($absolute, $copy, $mode): void {
                $directory = dirname($absolute);

                if (! is_dir($directory)) {
                    mkdir($directory, 0755, true);
                }

                // Beside the target, then renamed over it: the restored file may
                // be read-only (its backed-up mode), which a copy() onto it
                // would fail on — leaving the restored content in place while
                // the restore reports itself rolled back.
                $temp = $absolute.'.bm-rollback-tmp';

                if (! @copy($copy, $temp)) {
                    throw RestoreException::failed("Rollback could not put back [{$absolute}].");
                }

                // Put back the access it had, not whatever copy() left.
                if ($mode !== null) {
                    @chmod($temp, $mode);
                }

                if (! @rename($temp, $absolute)) {
                    @unlink($temp);

                    throw RestoreException::failed("Rollback could not put back [{$absolute}].");
                }
            });
        }

        foreach ($point->createdFiles as $absolute) {
            $this->safely(function () use ($absolute): void {
                if (is_file($absolute)) {
                    unlink($absolute);
                }
            });
        }

        return $this->failures;
    }

    /**
     * Whether a dump failed because the database is simply not there yet —
     * MySQL/MariaDB error 1049, PostgreSQL's "does not exist", or an SQLite
     * file that is missing or in-memory.
     */
    private static function isMissingDatabase(Throwable $exception): bool
    {
        // Only the dump error itself — the client's stderr — is read. The
        // wrapped process exception also carries the full command line
        // (`--port=1049`, `--host=db-1049`, the database name), and a pattern
        // that matched there would mistake a timeout or a full disk for "no
        // database yet" and drop the safety net.
        return preg_match(
            '/Unknown database \'|Got error: 1049\b|ERROR 1049\b|database "[^"]*" does not exist|does not exist or is in-memory/',
            $exception->getMessage(),
        ) === 1;
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

            // A database that does not exist yet has nothing to roll back to,
            // so it is skipped. Any other failure — a timeout, a full disk, a
            // missing client — aborts the restore before it touches anything:
            // carrying on would overwrite the live database with no way back,
            // which is the one outcome this guard exists to prevent.
            try {
                $this->databases->dumper($target->driver)->dump($target, $path);
                $point->databases[$artifact->connection] = ['path' => $path, 'target' => $target];

                // A rollback replays this dump through the same restorer, which
                // inspects it. Inspect it now, so a dump the inspector would
                // refuse stops the restore here — before anything changes —
                // rather than halfway through putting things back.
                SqlDumpInspector::assertSafe($target->driver, $path);
            } catch (Throwable $exception) {
                if (! self::isMissingDatabase($exception)) {
                    throw RestoreException::rollbackUnavailable($artifact->connection, $exception);
                }

                $this->logger->info('[backup-manager] Database does not exist yet; nothing to snapshot for rollback', [
                    'connection' => $artifact->connection,
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

            // Normalised exactly as RestoreManager does, so the rollback copy is
            // taken of the very file the restore is about to overwrite.
            $absolute = SafePath::lexical(ArchivePath::toAbsolute($file->path));

            if ($options->isSelective() && ! $this->matchesSelection($absolute, $options->onlyPaths)) {
                continue;
            }

            if (! is_file($absolute)) {
                $point->createdFiles[] = $absolute;

                continue;
            }

            $copy = $filesDir.'/'.md5($absolute);

            if (! is_dir($filesDir) && ! @mkdir($filesDir, 0700, true) && ! is_dir($filesDir)) {
                throw RestoreException::rollbackUnavailable($absolute);
            }

            // A file that cannot be copied aside cannot be put back either.
            if (! @copy($absolute, $copy)) {
                throw RestoreException::rollbackUnavailable($absolute);
            }

            $point->files[$absolute] = $copy;
            $perms = @fileperms($absolute);
            $point->modes[$absolute] = is_int($perms) ? $perms & 0777 : null;
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
            $this->failures[] = $exception->getMessage();
            $this->logger->error('[backup-manager] Rollback step failed', ['error' => $exception->getMessage()]);
        }
    }
}
