<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Exceptions\StorageException;
use Nyoncode\BackupManager\Support\BackupContext;
use Throwable;

/**
 * Streams the finished archive and its manifest to every destination disk.
 *
 * Destinations are ordinary Laravel filesystem disks, so local, S3-compatible,
 * FTP, SFTP, Azure and other adapters are supported with no package-specific
 * code. The manifest is stored as a sidecar so it can be read (and the archive
 * verified) without downloading or decrypting the archive itself.
 */
final class StoreBackup implements BackupStage
{
    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        $directory = trim($context->definition->path, '/');
        $archiveTarget = "{$directory}/{$context->archiveFileName}";
        $manifestTarget = "{$directory}/{$context->manifestFileName()}";
        $manifestJson = $context->manifest?->toJson() ?? '';

        foreach ($context->definition->destinations as $diskName) {
            try {
                $disk = $this->filesystem->disk($diskName);

                $stream = fopen($context->archivePath, 'rb');

                if ($stream === false) {
                    throw StorageException::writeFailed($diskName, $archiveTarget);
                }

                $disk->put($archiveTarget, $stream);
                fclose($stream);

                $disk->put($manifestTarget, $manifestJson);
            } catch (Throwable $exception) {
                // Best-effort cleanup so a failed upload does not leave an
                // orphaned, unusable half-archive on the destination.
                $this->discardPartial($diskName, [$archiveTarget, $manifestTarget]);

                throw StorageException::writeFailed($diskName, $archiveTarget, $exception);
            }
        }

        return $next($context);
    }

    /**
     * @param  list<string>  $paths
     */
    private function discardPartial(string $diskName, array $paths): void
    {
        try {
            $disk = $this->filesystem->disk($diskName);

            foreach ($paths as $path) {
                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            }
        } catch (Throwable) {
            // Nothing more we can do; surface the original failure instead.
        }
    }
}
