<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Actions\Files\CollectFiles;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Support\BackupContext;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\CollectedFile;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;

/**
 * Collects the configured source files, registering regular files as archive
 * entries with per-file checksums and symlinks as manifest-only metadata.
 */
final class CollectSourceFiles implements BackupStage
{
    public function __construct(
        private readonly CollectFiles $collector,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        if (! $context->definition->type->includesFiles()) {
            return $next($context);
        }

        foreach ($this->collector->execute($context->definition->files) as $file) {
            $context->manifestFiles[] = $this->toManifestFile($file, $context);

            if (! $file->isSymlink) {
                $context->fileEntries[$file->archivePath] = $file->absolutePath;
            }
        }

        return $next($context);
    }

    private function toManifestFile(CollectedFile $file, BackupContext $context): ManifestFile
    {
        $checksum = $file->isSymlink
            ? Checksum::forContents((string) $file->symlinkTarget, $context->checksum)
            : Checksum::forFile($file->absolutePath, $context->checksum);

        return new ManifestFile(
            path: $file->archivePath,
            size: $file->size,
            checksum: $checksum,
            isSymlink: $file->isSymlink,
            symlinkTarget: $file->symlinkTarget,
            // Restored as it was: a `.env` or a private key must not come back
            // world-readable.
            mode: $file->isSymlink ? null : self::mode($file->absolutePath),
        );
    }

    private static function mode(string $path): ?int
    {
        $perms = @fileperms($path);

        return is_int($perms) ? $perms & 0777 : null;
    }
}
