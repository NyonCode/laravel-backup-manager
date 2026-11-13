<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Commands;

use Illuminate\Console\Command;
use Nyoncode\BackupManager\Support\ComposerAuthFile;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Activates a Pro licence and installs nyoncode/laravel-backup-manager-pro.
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
        {token? : Your Pro licence token (omit it to be prompted, or set BACKUP_PRO_TOKEN)}
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

        if ($provided !== null) {
            // Too late to take it back, but worth knowing: an argument is in the
            // shell history and visible to other local users in `ps`.
            $this->components->warn('The token was passed as an argument, so it is now in your shell history. Next time run the command without it (you will be prompted) or set BACKUP_PRO_TOKEN.');
        } elseif (is_string(getenv('BACKUP_PRO_TOKEN')) && getenv('BACKUP_PRO_TOKEN') !== '') {
            $provided = (string) getenv('BACKUP_PRO_TOKEN');
        }

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
        $package = $this->stringOption('package') ?: (string) config('backup-manager.pro.package', 'nyoncode/laravel-backup-manager-pro');
        $username = $this->stringOption('username', 'token');
        $host = (string) (parse_url($repository, PHP_URL_HOST) ?: $repository);

        // The licence token is sent to this host as HTTP basic credentials.
        if (parse_url($repository, PHP_URL_SCHEME) !== 'https' && ! in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            $this->components->error("Refusing to send the licence token over plain HTTP to [{$repository}]. Use an https:// repository URL.");

            return self::INVALID;
        }

        // Configure the private repo + credentials, then pull the package. The
        // repository is keyed `nyoncode` so one entry (and one licence key) serves
        // every purchased NyonCode package on the host. The credentials are written
        // straight into auth.json (never committed) rather than via
        // `composer config --auth`, so the licence token never lands on the process
        // argv where other local users could read it from `ps`/`/proc`. The steps
        // below are the equivalent commands, shown for the dry run and the manual
        // fallback with the token masked.
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

        // 1. Register the private repository (no secret on the command line).
        if (! $this->runDisplayedStep($steps[0], $token)) {
            return $this->manualFallback($steps, $token);
        }

        // 2. Write the credentials into auth.json directly — off the argv.
        $this->line('<fg=gray>$ '.$this->render($steps[1], $token).'</>');

        try {
            (new ComposerAuthFile(base_path().'/auth.json'))->putHttpBasic($host, $username, $token);
        } catch (Throwable $exception) {
            $this->components->error('Could not write auth.json: '.$exception->getMessage());

            return $this->manualFallback($steps, $token);
        }

        // 3. Pull the package.
        if (! $this->runDisplayedStep($steps[2], $token)) {
            return $this->manualFallback($steps, $token);
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
     * Echo a command (token masked) and run it.
     *
     * @param  list<string>  $command
     */
    private function runDisplayedStep(array $command, string $token): bool
    {
        $this->line('<fg=gray>$ '.$this->render($command, $token).'</>');

        return $this->runStep($command);
    }

    /**
     * @param  list<list<string>>  $steps
     */
    private function manualFallback(array $steps, string $token): int
    {
        $this->newLine();
        $this->components->error('Upgrade did not complete. Finish it manually from '.base_path().':');
        $this->printSteps($steps, $token);

        return self::FAILURE;
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
