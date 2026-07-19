<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Managers\DatabaseManager;
use Nyoncode\BackupManager\Support\BackupContext;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\DatabaseArtifact;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;

/**
 * Dumps every configured database into the workspace and registers each dump
 * as both an archive entry and a manifest record.
 */
final class DumpDatabases implements BackupStage
{
    public function __construct(
        private readonly DatabaseManager $databases,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        $targets = $context->definition->databases;

        if ($targets === []) {
            return $next($context);
        }

        $directory = "{$context->workspace}/databases";

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new BackupManagerException("Unable to create databases directory [{$directory}].");
        }

        foreach ($targets as $target) {
            $fileName = "{$target->connection}.{$target->driver->dumpExtension()}";
            $dumpPath = "{$directory}/{$fileName}";
            $archivePath = "databases/{$fileName}";

            $this->databases->dumper($target->driver)->dump($target, $dumpPath);

            $context->fileEntries[$archivePath] = $dumpPath;
            $context->manifestFiles[] = new ManifestFile(
                path: $archivePath,
                size: (int) (filesize($dumpPath) ?: 0),
                checksum: Checksum::forFile($dumpPath, $context->checksum),
            );
            $context->databaseArtifacts[] = new DatabaseArtifact(
                connection: $target->connection,
                driver: $target->driver,
                databaseName: $target->database,
                archivedPath: $archivePath,
            );
        }

        return $next($context);
    }
}
