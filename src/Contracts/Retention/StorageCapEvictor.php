<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Retention;

use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Capability: evict backups from a keep-set to respect a total-storage cap.
 *
 * Total-size eviction (`max_storage_megabytes`) is a Pro capability. The free
 * core has no evictor bound, so a size cap set without Pro is simply not
 * enforced — which keeps *more* backups, the fail-safe direction for retention.
 * The Pro package binds the real evictor.
 */
interface StorageCapEvictor
{
    /**
     * @param  list<StoredBackup>  $keep  The strategy's keep-set, newest first.
     * @return list<StoredBackup> The keep-set trimmed to fit the storage cap.
     */
    public function evict(array $keep, RetentionPolicy $policy): array;
}
