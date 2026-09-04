<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands\Concerns;

use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;

/**
 * Shared helpers for commands that operate on stored backups.
 */
trait ResolvesBackups
{
    protected function definition(ProfileResolver $resolver): BackupDefinition
    {
        return $resolver->resolve($this->profileOption());
    }

    protected function disk(BackupDefinition $definition): string
    {
        return $this->diskOption() ?? ($definition->destinations[0] ?? 'local');
    }

    protected function profileOption(): ?string
    {
        $profile = $this->option('profile');

        return is_string($profile) && $profile !== '' ? $profile : null;
    }

    protected function diskOption(): ?string
    {
        $disk = $this->option('disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    protected function backupArgument(): ?string
    {
        $backup = $this->argument('backup');

        return is_string($backup) && $backup !== '' ? $backup : null;
    }

    /**
     * Find a backup by name on the destination, or the newest when no name is
     * given. Both self-contained archives and incremental snapshots qualify.
     */
    protected function findBackup(
        BackupRepository $repository,
        BackupDefinition $definition,
        ?string $name,
    ): ?StoredBackup {
        $disk = $this->disk($definition);

        $backups = array_values(array_filter(
            array_merge(
                $repository->list($disk, $definition->path),
                $repository->listSnapshots($disk, $definition->path),
            ),
            // A profile only offers up its own backups, even where several
            // profiles write to the same disk and path.
            static fn (StoredBackup $backup): bool => $backup->belongsTo($definition->profile),
        ));

        usort($backups, static fn (StoredBackup $a, StoredBackup $b): int => $b->createdAt <=> $a->createdAt);

        if ($name === null) {
            return $backups[0] ?? null;
        }

        foreach ($backups as $backup) {
            if ($backup->name === $name) {
                return $backup;
            }
        }

        return null;
    }
}
