<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Backup;

use Closure;
use Nyoncode\BackupManager\Contracts\Backup\BackupStage;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use Nyoncode\BackupManager\Support\BackupContext;

/**
 * Creates an isolated working directory for the backup and seeds archive paths.
 */
final class PrepareWorkspace implements BackupStage
{
    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    public function handle(BackupContext $context, Closure $next): BackupContext
    {
        $workspace = rtrim($this->workingDirectory, '/')."/{$context->baseName}";

        if (! is_dir($workspace) && ! mkdir($workspace, 0755, true) && ! is_dir($workspace)) {
            throw new BackupManagerException("Unable to create working directory [{$workspace}].");
        }

        $context->workspace = $workspace;
        $context->tarPath = "{$workspace}/{$context->baseName}.tar";

        return $next($context);
    }
}
