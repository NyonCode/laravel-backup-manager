<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Managers\CompressorManager;
use Nyoncode\BackupManager\Support\BackupContext;

/**
 * Compresses the TAR archive with the configured algorithm (or leaves it as-is).
 */
final class CompressArchive implements BackupStage
{
    public function __construct(
        private readonly CompressorManager $compressors,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        if (! $context->compression->isEnabled()) {
            return $next($context);
        }

        $compressor = $this->compressors->for($context->compression);
        $destination = "{$context->archivePath}.{$compressor->extension()}";

        $compressor->compress($context->archivePath, $destination);
        $this->discard($context->archivePath);

        $context->compressedPath = $destination;
        $context->archivePath = $destination;

        return $next($context);
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
