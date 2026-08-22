<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Events;

use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Dispatched after a stored backup has been deleted on request.
 *
 * Retention prunes on a policy and stays silent; this fires only for a targeted
 * deletion, so a listener can record who removed which backup and when. The
 * actor is whatever the caller chose to identify itself with — a dashboard
 * passes the signed-in user, the CLI passes nothing — because the core has no
 * notion of an authenticated request.
 */
final readonly class BackupDeleted
{
    public function __construct(
        public StoredBackup $backup,
        public ?string $actor = null,
    ) {}
}
