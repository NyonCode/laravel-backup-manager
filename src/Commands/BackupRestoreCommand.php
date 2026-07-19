<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Nyoncode\BackupManager\Commands\Concerns\ResolvesBackups;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Managers\RestoreManager;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Throwable;

/**
 * Restores a backup: full, database-only, files-only or a selective subset.
 */
final class BackupRestoreCommand extends Command
{
    use ResolvesBackups;

    protected $signature = 'backup:restore
        {backup? : The backup file name to restore (defaults to the newest)}
        {--profile= : The backup profile}
        {--disk= : Override the destination disk}
        {--database : Restore databases only}
        {--files : Restore files only}
        {--only=* : Restore only files under these absolute path prefixes}
        {--no-verify : Skip archive integrity verification}
        {--unsafe : Skip the pre-restore snapshot / rollback safety net}
        {--force : Do not ask for confirmation}';

    protected $description = 'Restore a backup';

    public function handle(ProfileResolver $resolver, BackupRepository $repository, RestoreManager $restore): int
    {
        $definition = $this->definition($resolver);
        $backup = $this->findBackup($repository, $definition, $this->backupArgument());

        if ($backup === null) {
            $this->components->error('No matching backup found.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm(
            sprintf('Restore [%s]? This overwrites existing databases and files.', $backup->name),
        )) {
            $this->components->warn('Restore aborted.');

            return self::SUCCESS;
        }

        $this->components->info("Restoring {$backup->name}…");

        try {
            $result = $restore->restore($backup, $this->restoreOptions());
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Databases restored', implode(', ', $result->restoredDatabases) ?: '—');
        $this->components->twoColumnDetail('Files restored', (string) $result->restoredFileCount);
        $this->components->info('Restore completed.');

        return self::SUCCESS;
    }

    private function restoreOptions(): RestoreOptions
    {
        $verify = ! $this->option('no-verify');
        $safe = ! $this->option('unsafe');

        /** @var list<string> $only */
        $only = (array) $this->option('only');

        if ($only !== []) {
            return RestoreOptions::selective($only, $verify, $safe);
        }

        $database = (bool) $this->option('database');
        $files = (bool) $this->option('files');

        return match (true) {
            $database && ! $files => RestoreOptions::databasesOnly($verify, $safe),
            $files && ! $database => RestoreOptions::filesOnly($verify, $safe),
            default => RestoreOptions::full($verify, $safe),
        };
    }
}
