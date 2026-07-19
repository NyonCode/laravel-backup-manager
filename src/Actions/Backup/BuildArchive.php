<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Contracts\Archive\Archiver;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Support\BackupContext;

/**
 * Assembles the collected database dumps and files into a single TAR archive.
 */
final class BuildArchive implements BackupStage
{
    public function __construct(
        private readonly Archiver $archiver,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        if ($context->fileEntries === []) {
            throw new BackupManagerException(
                "Nothing to back up for profile [{$context->definition->profile}]: no databases and no files matched.",
            );
        }

        $this->archiver->create($context->fileEntries, [], $context->tarPath);

        // The archive under construction becomes the current artifact; the
        // compression and encryption stages advance this path as they run.
        $context->archivePath = $context->tarPath;

        return $next($context);
    }
}
