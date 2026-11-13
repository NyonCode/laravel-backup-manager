<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use FilesystemIterator;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Support\BackupContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Removes the temporary workspace once the backup has been stored.
 */
final class CleanupWorkspace implements BackupStage
{
    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        self::remove($context->workspace);

        return $next($context);
    }

    /**
     * Delete a workspace and everything in it. Also called when a backup
     * fails part-way — the pipeline never reaches this stage then, and the
     * workspace holds plaintext database dumps.
     */
    public static function remove(string $directory): void
    {
        if ($directory === '' || ! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
