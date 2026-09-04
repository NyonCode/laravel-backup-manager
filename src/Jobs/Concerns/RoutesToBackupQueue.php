<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Jobs\Concerns;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository;

/**
 * Routes a job onto the connection, queue and timeout configured for backups.
 *
 * Backup work does not belong on the application's default queue. The default
 * connection is typically tuned for web work — a worker timeout measured in
 * seconds and a `retry_after` well under an hour — and a backup that outlives
 * either is not merely slow: the worker kills it mid-archive, or the queue
 * declares it lost and starts a second run beside the first, over the same
 * destination and the same deduplicated pool.
 *
 * Call {@see routeToBackupQueue()} from the constructor so the routing is part
 * of the job before it is ever pushed. A caller that chains `onQueue()` or
 * `onConnection()` onto the dispatch still wins — that runs afterwards.
 *
 * Requires {@see Queueable}.
 */
trait RoutesToBackupQueue
{
    /**
     * Seconds the job may run before the worker times it out.
     */
    public int $timeout = 3600;

    /**
     * @param  string|null  $timeoutKey  A `backup-manager.queue.*` key holding a
     *                                   longer timeout for this kind of work;
     *                                   falls back to `queue.timeout` when unset.
     */
    protected function routeToBackupQueue(?string $timeoutKey = null): void
    {
        $config = app(Repository::class);

        $queue = $config->get('backup-manager.queue.queue');

        $this->onConnection($config->get('backup-manager.queue.connection'));
        // Explicitly null, not merely absent, is the shape a published config
        // takes when its env var is set but empty — and an empty queue name is
        // not a queue the worker listens on.
        $this->onQueue(is_string($queue) && $queue !== '' ? $queue : 'backups');

        $this->timeout = $this->resolveTimeout($config, $timeoutKey);
    }

    private function resolveTimeout(Repository $config, ?string $timeoutKey): int
    {
        $default = (int) $config->get('backup-manager.queue.timeout', 3600);

        if ($timeoutKey === null) {
            return $default;
        }

        $override = $config->get("backup-manager.queue.{$timeoutKey}");

        return is_numeric($override) && (int) $override > 0 ? (int) $override : $default;
    }
}
