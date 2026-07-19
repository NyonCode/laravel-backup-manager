<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Backup;

use Closure;
use Nyoncode\BackupManager\Support\BackupContext;

/**
 * Capability: one step in the backup pipeline.
 *
 * Stages receive the shared context, do their work, then call $next to pass
 * control on. Implemented to plug directly into Laravel's Pipeline.
 */
interface BackupStage
{
    public function handle(BackupContext $context, Closure $next): BackupContext;
}
