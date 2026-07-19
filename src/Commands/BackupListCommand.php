<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Nyoncode\BackupManager\Commands\Concerns\ResolvesBackups;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Support\Bytes;
use Nyoncode\BackupManager\Support\ProfileResolver;

/**
 * Lists the backups stored for a profile on a destination.
 */
final class BackupListCommand extends Command
{
    use ResolvesBackups;

    protected $signature = 'backup:list
        {--profile= : The backup profile}
        {--disk= : Override the destination disk to inspect}';

    protected $description = 'List stored backups';

    public function handle(ProfileResolver $resolver, BackupRepository $repository): int
    {
        $definition = $this->definition($resolver);
        $disk = $this->disk($definition);

        $backups = array_merge(
            $repository->list($disk, $definition->path),
            $repository->listSnapshots($disk, $definition->path),
        );

        usort($backups, static fn ($a, $b): int => $b->createdAt <=> $a->createdAt);

        if ($backups === []) {
            $this->components->warn(sprintf('No backups found for profile [%s] on disk [%s].', $definition->profile, $disk));

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Mode', 'Created', 'Size'],
            array_map(static fn ($backup): array => [
                $backup->name,
                $backup->mode->isIncremental() ? 'snapshot' : 'archive',
                $backup->createdAt->format('Y-m-d H:i:s'),
                Bytes::human($backup->size),
            ], $backups),
        );

        $total = array_sum(array_map(static fn ($backup): int => $backup->size, $backups));
        $this->components->twoColumnDetail('<fg=gray>Total</>', sprintf('%d backups · %s', count($backups), Bytes::human($total)));

        return self::SUCCESS;
    }
}
