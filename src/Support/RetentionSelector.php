<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use DateTimeImmutable;
use Nyoncode\BackupManager\Contracts\Retention\StorageCapEvictor;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Computes the keep-set for a retention policy.
 *
 * The named strategy (resolved from the {@see RetentionStrategyRegistry}) decides
 * the base keep-set; the age cap is then layered on, and finally the optional
 * total-storage cap. The logic is purely time- and size-based, so it applies
 * unchanged to self-contained archives and to incremental snapshots alike.
 *
 * The edition boundary lives here: the base strategy comes from whatever the
 * registry holds (core: `simple`; Pro adds `gfs`), and total-storage eviction is
 * a Pro capability injected as an optional {@see StorageCapEvictor}. Both fail
 * closed — an unregistered strategy keeps every backup, and with no evictor the
 * size cap is simply not enforced — so retention never deletes a backup it was
 * not explicitly configured and equipped to delete.
 */
final class RetentionSelector
{
    public function __construct(
        private readonly RetentionStrategyRegistry $strategies,
        private readonly ?StorageCapEvictor $storageEvictor = null,
    ) {}

    /**
     * @param  list<StoredBackup>  $backups  Newest first.
     * @return list<StoredBackup> The subset to retain, newest first.
     */
    public function keep(array $backups, RetentionPolicy $policy): array
    {
        $strategy = $this->strategies->make($policy->strategy, $policy);

        if ($strategy === null) {
            // Fail closed: the named strategy is not available in this
            // installation (e.g. `gfs` without the Pro package). Keep every
            // backup rather than prune under a policy we cannot honour.
            return $backups;
        }

        $keep = $this->applyAgeLimit($strategy->keep($backups), $policy);

        if ($this->storageEvictor !== null) {
            $keep = $this->storageEvictor->evict($keep, $policy);
        }

        return $keep;
    }

    /**
     * @param  list<StoredBackup>  $keep
     * @return list<StoredBackup>
     */
    private function applyAgeLimit(array $keep, RetentionPolicy $policy): array
    {
        if ($policy->maxAgeDays === null) {
            return $keep;
        }

        $threshold = (new DateTimeImmutable)->modify("-{$policy->maxAgeDays} days");

        return array_values(array_filter(
            $keep,
            static fn (StoredBackup $backup): bool => $backup->createdAt >= $threshold,
        ));
    }
}
