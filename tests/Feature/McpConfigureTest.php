<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Exceptions\ConfigWriteException;
use Nyoncode\BackupManager\Support\ConfigReference;
use Nyoncode\BackupManager\Support\ConfigWriter;
use Nyoncode\BackupManager\Support\EnvFile;
use Nyoncode\BackupManager\Support\PhpConfigFile;

beforeEach(function (): void {
    $this->appDir = sys_get_temp_dir().'/bm-app-'.uniqid();
    $this->configFile = "{$this->appDir}/config/backup-manager.php";
    $this->envFile = "{$this->appDir}/.env";
    $this->stub = dirname(__DIR__, 2).'/config/backup-manager.php';

    mkdir("{$this->appDir}/config", 0777, true);
    copy($this->stub, $this->configFile);
    file_put_contents($this->envFile, "APP_ENV=testing\nAPP_DEBUG=true\n");

    // A configuration with no errors of its own, so a refusal in these tests is
    // caused by the change under test and nothing else.
    touch("{$this->appDir}/database.sqlite");
    config()->set('database.connections.bm_sqlite', [
        'driver' => 'sqlite',
        'database' => "{$this->appDir}/database.sqlite",
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('backup-manager.profiles.default.databases.connections', ['bm_sqlite']);
    config()->set('backup-manager.profiles.default.files.include', [$this->appDir]);
    config()->set('backup-manager.schedule.enabled', true);
    config()->set('backup-manager.monitoring.notifications.mail.to', ['ops@example.com']);

    // Point the writer at a throwaway application rather than the test skeleton.
    $this->app->singleton(ConfigWriter::class, fn (): ConfigWriter => new ConfigWriter(
        new ConfigReference,
        new PhpConfigFile($this->configFile),
        new EnvFile($this->envFile),
        $this->stub,
        "{$this->appDir}/storage/config-backups",
    ));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->appDir));
});

it('previews a change without writing anything', function (): void {
    $before = (string) file_get_contents($this->configFile);

    $result = mcpTool('backup_configure', [
        'config' => ['retention.simple.keep_latest' => 14],
        'env' => ['BACKUP_SCHEDULE' => true],
    ]);
    $data = (array) $result['structuredContent'];

    expect($result['isError'])->toBeFalse()
        ->and($data['dry_run'])->toBeTrue()
        ->and(array_column($data['changes'], 'key'))->toContain('retention.simple.keep_latest', 'BACKUP_SCHEDULE')
        ->and($data)->toHaveKey('resulting_findings')
        ->and((string) file_get_contents($this->configFile))->toBe($before)
        ->and((string) file_get_contents($this->envFile))->not->toContain('BACKUP_SCHEDULE');
});

it('writes the configuration and keeps the comments', function (): void {
    $data = mcpData('backup_configure', [
        'config' => [
            'retention.simple.keep_latest' => 14,
            'profiles.hourly.type' => 'database',
            'profiles.hourly.destinations' => ['local'],
            'profiles.hourly.databases.connections' => ['bm_sqlite'],
        ],
        'dry_run' => false,
    ]);

    $written = require $this->configFile;

    expect($data['dry_run'])->toBeFalse()
        ->and($written['retention']['simple']['keep_latest'])->toBe(14)
        ->and($written['profiles']['hourly'])->toBe([
            'type' => 'database',
            'destinations' => ['local'],
            'databases' => ['connections' => ['bm_sqlite']],
        ])
        ->and((string) file_get_contents($this->configFile))->toContain('| Retention')
        ->and($data['files']['config_backup'])->toBeString()
        ->and(is_file((string) $data['files']['config_backup']))->toBeTrue();
});

it('routes a value that lives in the environment to .env', function (): void {
    $data = mcpData('backup_configure', [
        // The published file resolves this through env(), so rewriting the PHP
        // literal would detach it from the environment.
        'config' => ['compression.algorithm' => 'zstd'],
        'dry_run' => false,
    ]);

    expect((string) file_get_contents($this->envFile))->toContain('BACKUP_COMPRESSION=zstd')
        ->and((string) file_get_contents($this->configFile))->toContain("env('BACKUP_COMPRESSION'")
        ->and($data['notes'])->not->toBeEmpty()
        ->and(array_column($data['changes'], 'channel'))->toBe(['env']);
});

it('masks a secret it was asked to write', function (): void {
    $key = 'base64:'.base64_encode(random_bytes(32));

    $result = mcpTool('backup_configure', [
        'env' => ['BACKUP_ENCRYPTION' => 'aes-256-cbc', 'BACKUP_ENCRYPTION_KEY' => $key],
        'dry_run' => false,
    ]);

    expect($result['content'][0]['text'])->not->toContain($key)
        ->and((string) file_get_contents($this->envFile))->toContain($key);
});

it('refuses an unknown key and suggests the real one', function (): void {
    $result = mcpTool('backup_configure', ['config' => ['retention.simple.keep_latests' => 5]]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('retention.simple.keep_latest');
});

it('refuses a value the key does not allow', function (): void {
    $result = mcpTool('backup_configure', ['config' => ['compression.algorithm' => 'rar']]);

    expect($result['isError'])->toBeTrue()
        ->and($result['content'][0]['text'])->toContain('none, gzip, bzip2, zstd');
});

it('refuses an environment variable that is not ours', function (): void {
    $result = mcpTool('backup_configure', ['env' => ['APP_KEY' => 'nope'], 'dry_run' => false]);

    expect($result['isError'])->toBeTrue()
        ->and((string) file_get_contents($this->envFile))->not->toContain('nope');
});

it('refuses to apply a change that would leave the configuration broken', function (): void {
    $arguments = ['config' => ['encryption.algorithm' => 'aes-256-cbc'], 'dry_run' => false];

    $refused = mcpTool('backup_configure', $arguments);

    expect($refused['isError'])->toBeTrue()
        ->and($refused['content'][0]['text'])->toContain('error')
        ->and((string) file_get_contents($this->envFile))->not->toContain('BACKUP_ENCRYPTION=');

    $forced = mcpTool('backup_configure', $arguments + ['force' => true]);

    expect($forced['isError'])->toBeFalse()
        ->and((string) file_get_contents($this->envFile))->toContain('BACKUP_ENCRYPTION=aes-256-cbc');
});

it('applies the change to the running process as well', function (): void {
    mcpTool('backup_configure', [
        'config' => ['retention.simple.keep_latest' => 3],
        'dry_run' => false,
    ]);

    expect(config('backup-manager.retention.simple.keep_latest'))->toBe(3);
});

it('publishes the config file when the application has none', function (): void {
    unlink($this->configFile);

    $preview = mcpData('backup_configure', ['config' => ['profiles.default.path' => 'db-backups']]);

    expect($preview['notes'][0])->toContain('would be published')
        ->and(is_file($this->configFile))->toBeFalse();

    mcpTool('backup_configure', ['config' => ['profiles.default.path' => 'db-backups'], 'dry_run' => false]);

    expect(is_file($this->configFile))->toBeTrue()
        ->and((require $this->configFile)['profiles']['default']['path'])->toBe('db-backups');
});

it('does nothing when the value is already what was asked for', function (): void {
    $result = mcpTool('backup_configure', [
        'config' => ['profiles.default.path' => 'backups'],
        'dry_run' => false,
    ]);

    expect($result['isError'])->toBeFalse()
        ->and($result['content'][0]['text'])->toContain('No change');
});

it('asks for something to change', function (): void {
    expect(mcpTool('backup_configure')['isError'])->toBeTrue();
});

it('refuses to let the assistant widen its own access or swap a binary, even with force', function (string $key, mixed $value): void {
    $before = (string) file_get_contents($this->configFile);

    $result = mcpTool('backup_configure', ['config' => [$key => $value], 'dry_run' => false, 'force' => true]);

    expect($result['isError'])->toBeTrue()
        ->and((string) json_encode($result))->toContain('cannot be changed through the assistant')
        ->and((string) file_get_contents($this->configFile))->toBe($before);
})->with([
    'destructive tools' => ['mcp.allow_destructive', true],
    'every environment' => ['mcp.environments', ['*']],
    'dump binary' => ['binaries.mysqldump', '/tmp/evil'],
    'licence repository' => ['pro.repository', 'http://evil.test'],
    'plaintext staging' => ['working_directory', '/var/www/public'],
]);

it('never writes a .env value that dotenv would expand into another secret', function (): void {
    $result = mcpTool('backup_configure', [
        'env' => ['BACKUP_NOTIFY_SLACK' => 'https://evil.test/h?k=${APP_KEY}'],
        'dry_run' => false,
    ]);

    $env = (string) file_get_contents($this->envFile);

    expect($result['isError'])->toBeFalse()
        ->and($env)->toContain("BACKUP_NOTIFY_SLACK='https://evil.test/h?k=\${APP_KEY}'")
        ->and($env)->not->toContain('"https://evil.test');
});

it('lets the assistant set the first encryption key but never replace one', function (): void {
    config()->set('backup-manager.encryption.key', null);
    $first = 'base64:'.base64_encode(random_bytes(32));

    expect(mcpTool('backup_configure', ['env' => ['BACKUP_ENCRYPTION_KEY' => $first], 'dry_run' => true])['isError'])->toBeFalse();

    config()->set('backup-manager.encryption.key', $first);
    $result = mcpTool('backup_configure', ['env' => ['BACKUP_ENCRYPTION_KEY' => 'base64:'.base64_encode(random_bytes(32))], 'dry_run' => false, 'force' => true]);

    expect($result['isError'])->toBeTrue()
        ->and((string) json_encode($result))->toContain('already set');
});

it('never lets the assistant turn encryption off', function (): void {
    config()->set('backup-manager.encryption.algorithm', 'aes-256-cbc');

    $result = mcpTool('backup_configure', ['env' => ['BACKUP_ENCRYPTION' => 'none'], 'dry_run' => false, 'force' => true]);

    expect($result['isError'])->toBeTrue()
        ->and((string) json_encode($result))->toContain('may not turn it off');
});

it('never lets the writer turn signing off once it is on', function (): void {
    // Through the writer itself: with signing on and no Pro installed, the
    // core cannot build the MCP server at all — which is the point of it.
    config()->set('backup-manager.integrity.signing.enabled', true);

    app(ConfigWriter::class)->apply([], ['BACKUP_SIGNING' => false], dryRun: true);
})->throws(ConfigWriteException::class, 'may not turn it off');

it('treats a signing key that falls back to the encryption key as already set', function (): void {
    config()->set('backup-manager.integrity.signing.key', null);
    config()->set('backup-manager.encryption.key', 'base64:'.base64_encode(random_bytes(32)));

    app(ConfigWriter::class)->apply([], ['BACKUP_SIGNING_KEY' => 'base64:'.base64_encode(random_bytes(32))], dryRun: true);
})->throws(ConfigWriteException::class, 'already set');
