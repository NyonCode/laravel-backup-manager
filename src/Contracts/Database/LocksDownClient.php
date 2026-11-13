<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Contracts\Database;

use Nyoncode\BackupManager\Enums\DatabaseDriver;

/**
 * Capability: a restorer whose command-line client can import a dump with
 * the client's own commands switched off.
 *
 * `mysql` and `psql` run more than SQL — shell commands, file reads and writes
 * — and a dump can always find a way to reach them that no inspection of its
 * text anticipates. A client that refuses those commands itself is the only
 * guarantee, so a dump nothing authenticates is restored only through one.
 */
interface LocksDownClient
{
    /**
     * Whether restoring a dump for this driver runs the client locked down.
     */
    public function locksDownClient(DatabaseDriver $driver): bool;
}
