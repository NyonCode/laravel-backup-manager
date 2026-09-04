<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Nyoncode\BackupManager\Jobs\Concerns\RoutesToBackupQueue;
use Nyoncode\BackupManager\Managers\CleanupManager;
use Nyoncode\BackupManager\Support\ProfileResolver;

/**
 * Applies a profile's retention policy on the queue.
 *
 * Retention is not the small job its name suggests: pruning an incremental
 * profile reference-counts a whole blob pool before it sweeps, which on a large
 * installation runs for minutes. Scheduled without a queue that lands in the
 * scheduler's own process on the web machine — outside the worker's memory
 * limit, absent from the dashboard, and with a failure that goes nowhere. Queued
 * it is ordinary work with an ordinary failure record.
 *
 * Dry runs are never dispatched: their whole output is the preview, which a
 * queued job has nowhere to show.
 */
final class CleanBackupsJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use RoutesToBackupQueue;
    use SerializesModels;

    public function __construct(
        public readonly ?string $profile = null,
        public readonly ?string $disk = null,
    ) {
        $this->routeToBackupQueue();
    }

    public function handle(ProfileResolver $resolver, CleanupManager $cleanup): void
    {
        $cleanup->clean($resolver->resolve($this->profile), $this->disk);
    }
}
