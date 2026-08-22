<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Exceptions\ConfigWriteException;
use Nyoncode\BackupManager\Support\EnvFile;

beforeEach(function (): void {
    $this->path = sys_get_temp_dir().'/bm-env-'.uniqid().'.env';
    file_put_contents($this->path, <<<'ENV'
    APP_NAME="Acme Corp"
    APP_ENV=local

    # Database
    DB_CONNECTION=sqlite
    # BACKUP_QUEUE=true
    ENV);
});

afterEach(function (): void {
    @unlink($this->path);
});

it('reads values, unquoting and ignoring comments', function (): void {
    $env = new EnvFile($this->path);

    expect($env->get('APP_NAME'))->toBe('Acme Corp')
        ->and($env->get('DB_CONNECTION'))->toBe('sqlite')
        ->and($env->get('BACKUP_QUEUE'))->toBeNull()
        ->and($env->get('MISSING'))->toBeNull();
});

it('updates an existing variable in place and keeps every other line', function (): void {
    $env = new EnvFile($this->path);

    $changes = $env->set(['APP_ENV' => 'production']);

    expect($changes)->toBe([['key' => 'APP_ENV', 'from' => 'local', 'to' => 'production']])
        ->and($env->get('APP_ENV'))->toBe('production');

    $contents = (string) file_get_contents($this->path);

    expect($contents)->toContain('# Database')
        ->toContain('APP_NAME="Acme Corp"')
        ->toContain('# BACKUP_QUEUE=true');
});

it('appends a new variable under its own section', function (): void {
    $env = new EnvFile($this->path);

    $env->set(['BACKUP_SCHEDULE' => 'true', 'BACKUP_NOTIFY_MAIL' => 'ops@example.com']);

    $contents = (string) file_get_contents($this->path);

    expect($contents)->toContain('# Backup Manager')
        ->toContain('BACKUP_SCHEDULE=true')
        ->toContain('BACKUP_NOTIFY_MAIL=ops@example.com')
        // The commented-out variable stays commented; a new line was added instead.
        ->toContain('# BACKUP_QUEUE=true');
});

it('quotes values that would not survive a bare round trip', function (): void {
    $env = new EnvFile($this->path);

    $env->set(['BACKUP_NOTIFY_MAIL' => 'ops@example.com, sre@example.com']);

    expect((string) file_get_contents($this->path))->toContain('BACKUP_NOTIFY_MAIL="ops@example.com, sre@example.com"')
        ->and($env->get('BACKUP_NOTIFY_MAIL'))->toBe('ops@example.com, sre@example.com');
});

it('reports the change without writing on a dry run', function (): void {
    $before = (string) file_get_contents($this->path);
    $env = new EnvFile($this->path);

    $changes = $env->set(['APP_ENV' => 'production'], dryRun: true);

    expect($changes)->toHaveCount(1)
        ->and((string) file_get_contents($this->path))->toBe($before);
});

it('reports nothing when the value is already set', function (): void {
    expect((new EnvFile($this->path))->set(['APP_ENV' => 'local']))->toBe([]);
});

it('refuses to invent a missing .env', function (): void {
    $env = new EnvFile(sys_get_temp_dir().'/bm-env-absent-'.uniqid());

    expect(fn (): array => $env->set(['BACKUP_SCHEDULE' => 'true']))
        ->toThrow(ConfigWriteException::class);
});
