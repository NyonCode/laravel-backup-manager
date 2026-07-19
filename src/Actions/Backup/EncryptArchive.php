<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Managers\EncryptorManager;
use Nyoncode\BackupManager\Support\BackupContext;

/**
 * Encrypts the archive with AES-256 when encryption is enabled.
 */
final class EncryptArchive implements BackupStage
{
    public function __construct(
        private readonly EncryptorManager $encryptors,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        if (! $context->encryption->isEnabled()) {
            $context->archiveFileName = basename($context->archivePath);

            return $next($context);
        }

        $destination = "{$context->archivePath}.enc";

        $this->encryptors->for($context->encryption)->encrypt($context->archivePath, $destination);
        $this->discard($context->archivePath);

        $context->archivePath = $destination;
        $context->archiveFileName = basename($destination);

        return $next($context);
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
