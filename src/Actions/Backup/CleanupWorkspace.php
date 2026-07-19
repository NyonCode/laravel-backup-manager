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
        $this->delete($context->workspace);

        return $next($context);
    }

    private function delete(string $directory): void
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
