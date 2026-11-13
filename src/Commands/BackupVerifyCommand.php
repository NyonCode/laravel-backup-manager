<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Nyoncode\BackupManager\Commands\Concerns\ResolvesBackups;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Managers\VerifyManager;
use Nyoncode\BackupManager\Support\ProfileResolver;
use Throwable;

/**
 * Verifies the integrity of a stored backup without restoring it.
 */
final class BackupVerifyCommand extends Command
{
    use ResolvesBackups;

    protected $signature = 'backup:verify
        {backup? : The backup file name to verify (defaults to the newest)}
        {--profile= : The backup profile}
        {--disk= : Override the destination disk}';

    protected $description = 'Verify a backup archive against its manifest';

    public function handle(ProfileResolver $resolver, BackupRepository $repository, VerifyManager $verifier): int
    {
        $definition = $this->definition($resolver);
        $backup = $this->findBackup($repository, $definition, $this->backupArgument());

        if ($backup === null) {
            $this->components->error('No matching backup found.');

            return self::FAILURE;
        }

        $this->components->info("Verifying {$backup->name}…");

        try {
            $result = $verifier->verify($backup);
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->twoColumnDetail('Archive checksum', $result->checksumValid ? '<fg=green>valid</>' : '<fg=red>INVALID</>');
        $this->components->twoColumnDetail(
            'Signature',
            match ($result->signatureValid) {
                true => '<fg=green>valid</>',
                false => $result->unsigned()
                    ? '<fg=red>MISSING</> (signing is enabled; this backup carries no signature — taken before signing was turned on, or stripped)'
                    : '<fg=red>INVALID</>',
                null => '<fg=gray>not signed</>',
            },
        );

        if ($result->passed()) {
            $this->components->info('Backup verified successfully.');

            return self::SUCCESS;
        }

        $this->components->error('Backup verification failed.');

        return self::FAILURE;
    }
}
