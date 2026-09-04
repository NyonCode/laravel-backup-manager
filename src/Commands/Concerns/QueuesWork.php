<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands\Concerns;

/**
 * The shared `--sync` decision for commands that can hand their work to a queue.
 *
 * `BACKUP_QUEUE=true` reads as "backup work goes to the queue"; it has to mean
 * that for every command that does backup work, not just for `backup:run`, or
 * the setting quietly covers a third of what it names. Each command declares the
 * `--sync` flag itself so its own help text stays honest.
 */
trait QueuesWork
{
    protected function shouldQueue(): bool
    {
        return (bool) config('backup-manager.queue.enabled', false) && ! $this->option('sync');
    }
}
