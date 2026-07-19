<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * The outcome of a restore operation.
 */
final readonly class RestoreResult
{
    /**
     * @param  list<string>  $restoredDatabases  Connection names restored.
     */
    public function __construct(
        public string $backupName,
        public array $restoredDatabases,
        public int $restoredFileCount,
        public Manifest $manifest,
    ) {}
}
