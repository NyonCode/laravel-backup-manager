<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Exceptions;

use Throwable;

/**
 * Thrown when a restore operation cannot be completed.
 */
final class RestoreException extends BackupManagerException
{
    public static function backupNotFound(string $identifier): self
    {
        return new self("Backup [{$identifier}] could not be found in the configured destination.");
    }

    public static function rollbackUnavailable(string $what, ?Throwable $previous = null): self
    {
        return new self(
            "Restore aborted before changing anything: the current state of [{$what}] could not be saved for rollback"
            .($previous !== null ? ' ('.$previous->getMessage().')' : '').'. '
            .'Fix the cause, or restore without the safety net deliberately (--unsafe).',
            0,
            $previous,
        );
    }

    /**
     * @param  list<string>  $paths
     */
    public static function outsideIncludePaths(array $paths, ?string $unknownProfile = null): self
    {
        $shown = array_slice($paths, 0, 5);

        $why = $unknownProfile !== null
            ? ($unknownProfile === ''
                ? 'The backup does not say which profile wrote it, so there are no include paths to check against. '
                : "The backup was written by profile [{$unknownProfile}], which is no longer configured, so there are no include paths to check against. ")
            : 'Either the manifest was tampered with, or the include paths changed since the backup (the app moved, or a new release directory). ';

        return new self(sprintf(
            'Restore refused before changing anything: %d file(s) in this backup lie outside the profile\'s current files.include paths (%s%s). %s'
            .'If you trust this backup, run `backup:restore --allow-outside` (or set BACKUP_RESTORE_CONFINE=false).',
            count($paths),
            implode(', ', $shown),
            count($paths) > count($shown) ? ', …' : '',
            $why,
        ));
    }

    public static function untrustedDump(string $connection, string $driver): self
    {
        return new self(sprintf(
            'Restore refused before changing anything: the dump for [%s] is not authenticated (the backup is unsigned), '
            .'and this %s client cannot run it with its own commands switched off — a tampered dump could run shell '
            .'commands on this host. Sign backups (Pro: BACKUP_SIGNING=true), or use a client that locks down '
            .'(MariaDB 10.5.25+/11.x --sandbox, MySQL 8.4.6+ --commands, psql 13.22/14.19/15.14/16.10/17.6+), '
            .'or, if you trust this backup, run `backup:restore --trust-unsigned-dump`.',
            $connection,
            $driver,
        ));
    }

    public static function profileMismatch(string $recorded, string $expected): self
    {
        return new self(sprintf(
            'Restore refused: the backup says it was written by profile [%s], but it is being restored for [%s]. '
            .'The manifest decides nothing about where files may go — the profile you restore for does — so the two must agree.',
            $recorded,
            $expected,
        ));
    }

    public static function unsafeDump(string $path, int $line, string $command): self
    {
        return new self(sprintf(
            'Restore refused: the database dump [%s] carries the client command [%s] on line %d. '
            .'A dump this package writes never does; a client command can run shell commands or read and write files on this host. '
            .'Treat the backup as tampered with.',
            basename($path),
            $command,
            $line,
        ));
    }

    public static function failed(string $reason, ?Throwable $previous = null): self
    {
        return new self("Restore failed: {$reason}", previous: $previous);
    }
}
