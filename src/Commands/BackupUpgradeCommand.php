<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Activates a Pro licence and installs nyoncode/backup-manager-pro.
 *
 * A free-core convenience: given a licence token it configures the private Pro
 * Composer repository and its credentials, then runs `composer require`. Pro's
 * service provider is auto-discovered, so the upgrade needs no code changes. If
 * anything fails (no network, no composer), the exact manual steps are printed
 * so nothing is left half-done.
 */
final class BackupUpgradeCommand extends Command
{
    protected $signature = 'backup:upgrade
        {token? : Your Pro licence token}
        {--username= : Auth username for the Pro repository (default: token)}
        {--repository= : Override the Pro Composer repository URL}
        {--package= : Override the Pro package name}
        {--dry-run : Show what would run without changing anything}';

    protected $description = 'Activate a Pro licence and install Backup Manager Pro';

    public function handle(): int
    {
        if ($this->proInstalled()) {
            $this->components->info('Backup Manager Pro is already installed — you are all set.');

            return self::SUCCESS;
        }

        $provided = $this->argument('token');

        if ($provided === null) {
            $prompt = $this->secret('Enter your Pro licence token');
            $provided = is_string($prompt) ? $prompt : '';
        }

        $token = trim(is_string($provided) ? $provided : '');

        if ($token === '') {
            $this->components->error('A licence token is required.');

            return self::INVALID;
        }

        $repository = $this->stringOption('repository') ?: (string) config('backup-manager.pro.repository');
        $package = $this->stringOption('package') ?: (string) config('backup-manager.pro.package', 'nyoncode/backup-manager-pro');
        $username = $this->stringOption('username', 'token');
        $host = (string) (parse_url($repository, PHP_URL_HOST) ?: $repository);

        // Configure the private repo + credentials, then pull the package. The
        // repository is keyed `nyoncode` so one entry (and one licence key) serves
        // every purchased NyonCode package on the host. Auth is written with
        // `composer config --auth` (auth.json), never committed.
        $steps = [
            ['composer', 'config', 'repositories.nyoncode', 'composer', $repository],
            ['composer', 'config', '--auth', "http-basic.{$host}", $username, $token],
            ['composer', 'require', $package],
        ];

        if ($this->option('dry-run')) {
            $this->components->info('Dry run — these would run in '.base_path().':');
            $this->printSteps($steps, $token);

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Activating Pro (token …%s) from %s', substr($token, -4), $host));

        foreach ($steps as $step) {
            $this->line('<fg=gray>$ '.$this->render($step, $token).'</>');

            if (! $this->runStep($step)) {
                $this->newLine();
                $this->components->error('Upgrade did not complete. Finish it manually from '.base_path().':');
                $this->printSteps($steps, $token);

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Backup Manager Pro installed. Incremental backups, signing, GFS retention, monitoring and history are now available.');

        return self::SUCCESS;
    }

    private function stringOption(string $name, string $default = ''): string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function proInstalled(): bool
    {
        // Referenced as a string so the free core never depends on the Pro
        // symbol; class_exists just reports whether Pro is installed.
        return class_exists('Nyoncode\\BackupManagerPro\\BackupManagerProServiceProvider');
    }

    /**
     * @param  list<string>  $command
     */
    private function runStep(array $command): bool
    {
        $process = new Process($command, base_path(), timeout: 300);
        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        return $process->isSuccessful();
    }

    /**
     * @param  list<list<string>>  $steps
     */
    private function printSteps(array $steps, string $token): void
    {
        foreach ($steps as $step) {
            $this->line('  '.$this->render($step, $token));
        }
    }

    /**
     * Render a command for display with the token masked.
     *
     * @param  list<string>  $command
     */
    private function render(array $command, string $token): string
    {
        return implode(' ', array_map(
            static fn (string $part): string => $part === $token ? '****'.substr($token, -4) : $part,
            $command,
        ));
    }
}
