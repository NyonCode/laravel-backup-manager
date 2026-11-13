<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\DTOs\DatabaseTarget;

/**
 * A snapshot of the pre-restore state, used to roll back on failure.
 *
 * Mutable by design: it is an accumulator populated during capture and read
 * back during a compensating rollback.
 */
final class RestorePoint
{
    /**
     * Database dumps of the current state, keyed by connection.
     *
     * @var array<string, array{path: string, target: DatabaseTarget}>
     */
    public array $databases = [];

    /**
     * Backup copies of files that existed before the restore.
     *
     * @var array<string, string> absolute path => backup copy path
     */
    public array $files = [];

    /**
     * Absolute paths of files that did not exist before and must be removed on rollback.
     *
     * @var list<string>
     */
    public array $createdFiles = [];

    /**
     * Permission bits of each saved file, so a rollback restores access too.
     *
     * @var array<string, int|null>
     */
    public array $modes = [];
}
