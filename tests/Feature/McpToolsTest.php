<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Support\ConfigReference;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-mcp-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    $this->dbFile = "{$this->work}/database.sqlite";
    mkdir($this->filesDir, 0777, true);
    file_put_contents("{$this->filesDir}/a.txt", 'a');
    touch($this->dbFile);

    config()->set('database.connections.bm_sqlite', [
        'driver' => 'sqlite',
        'database' => $this->dbFile,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);
    config()->set('backup-manager.default', 'test');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'none');
    config()->set('backup-manager.profiles', [
        'test' => [
            'name' => 'Acme',
            'type' => 'full',
            'databases' => ['connections' => ['bm_sqlite']],
            'files' => ['include' => [$this->filesDir], 'exclude' => ['vendor', 'node_modules']],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
    DB::connection('bm_sqlite')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');
});

it('summarises the installation', function (): void {
    $data = mcpData('backup_overview');

    expect($data['installation']['edition'])->toBe('core')
        ->and($data['default_profile'])->toBe('test')
        ->and($data['profiles'])->toHaveKey('test')
        ->and($data['capabilities']['incremental_backups'])->toBeFalse()
        ->and($data['mcp']['allow_destructive'])->toBeFalse()
        ->and($data['health'])->toHaveKeys(['error', 'warning', 'info']);
});

it('resolves every profile the way the pipeline does', function (): void {
    $data = mcpData('backup_list_profiles');

    expect($data['profiles']['test']['type'])->toBe('full')
        ->and($data['profiles']['test']['databases'][0]['connection'])->toBe('bm_sqlite')
        ->and($data['profiles']['test']['files']['include'])->toBe([$this->filesDir])
        ->and($data['profiles']['test']['retention']['inherited'])->toBeTrue();
});

it('reports what the host can do without leaking credentials', function (): void {
    config()->set('database.connections.bm_secret', [
        'driver' => 'mysql', 'database' => 'acme', 'host' => 'db', 'port' => 3306,
        'username' => 'root', 'password' => 'hunter2',
    ]);

    $result = mcpTool('backup_inspect_environment');
    $data = (array) $result['structuredContent'];

    expect($data['disks'])->toHaveKey('local')
        ->and($data['database_connections'])->toHaveKey('bm_sqlite')
        ->and($data['database_connections']['bm_secret'])->toHaveKeys(['driver', 'database', 'host'])
        ->and($data['binaries'])->toHaveKey('mysqldump')
        ->and($data['php_extensions']['zlib'])->toBeTrue()
        ->and($data['working_directory']['writable'])->toBeTrue()
        ->and($result['content'][0]['text'])->not->toContain('hunter2');
});

it('finds the problems in a configuration', function (): void {
    config()->set('backup-manager.profiles.test.destinations', ['nope']);
    config()->set('backup-manager.encryption.algorithm', 'aes-256-cbc');
    config()->set('backup-manager.encryption.key', null);

    $data = mcpData('backup_validate_configuration');
    $keys = array_column($data['errors'], 'key');

    expect($data['healthy'])->toBeFalse()
        ->and($keys)->toContain('profiles.test.destinations')
        ->and($keys)->toContain('encryption.key')
        ->and(array_column($data['warnings'], 'key'))->toContain('schedule.enabled');

    foreach ($data['errors'] as $error) {
        expect($error['message'])->not->toBeEmpty();
    }
});

it('calls a healthy configuration healthy', function (): void {
    config()->set('backup-manager.schedule.enabled', true);
    config()->set('backup-manager.monitoring.notifications.mail.to', ['ops@example.com']);

    expect(mcpData('backup_validate_configuration')['healthy'])->toBeTrue();
});

it('runs, lists and verifies a backup', function (): void {
    $run = mcpData('backup_run');

    expect($run['queued'])->toBeFalse()
        ->and($run['databases'])->toBe(1)
        ->and($run['destinations'])->toBe(['local'])
        ->and(Storage::disk('local')->files('backups'))->not->toBeEmpty();

    $list = mcpData('backup_list_backups');

    expect($list['count'])->toBe(1)
        ->and($list['backups'][0]['name'])->toBe($run['archive']);

    $verify = mcpTool('backup_verify');

    expect($verify['isError'])->toBeFalse()
        ->and($verify['structuredContent']['checksum_valid'])->toBeTrue()
        ->and($verify['structuredContent']['passed'])->toBeTrue();
});

it('reports a failed verification as an error result rather than a crash', function (): void {
    $run = mcpData('backup_run');
    Storage::disk('local')->put('backups/'.$run['archive'], 'tampered');

    $verify = mcpTool('backup_verify');

    expect($verify['isError'])->toBeTrue()
        ->and($verify['structuredContent']['checksum_valid'])->toBeFalse()
        ->and($verify['content'][0]['text'])->toContain('unusable');
});

it('says so when there is nothing to verify', function (): void {
    $verify = mcpTool('backup_verify', ['backup' => 'nope.tar.gz']);

    expect($verify['isError'])->toBeTrue()
        ->and($verify['content'][0]['text'])->toContain('nope.tar.gz');
});

it('previews retention without deleting anything', function (): void {
    config()->set('backup-manager.retention.simple.keep_latest', 1);
    config()->set('backup-manager.retention.max_age_days', null);

    mcpTool('backup_run');
    mcpTool('backup_run');

    $before = count(Storage::disk('local')->files('backups'));
    $preview = mcpData('backup_preview_retention');

    expect($preview['dry_run'])->toBeTrue()
        ->and($preview['total_deleted'])->toBe(1)
        ->and(Storage::disk('local')->files('backups'))->toHaveCount($before);
});

it('deletes only when the installation allows it', function (): void {
    config()->set('backup-manager.mcp.allow_destructive', true);
    config()->set('backup-manager.retention.simple.keep_latest', 1);
    config()->set('backup-manager.retention.max_age_days', null);

    mcpTool('backup_run');
    mcpTool('backup_run');

    // Even when allowed, the tool previews unless explicitly told to delete.
    expect(mcpData('backup_apply_retention')['dry_run'])->toBeTrue()
        ->and(Storage::disk('local')->files('backups'))->toHaveCount(4);

    $applied = mcpData('backup_apply_retention', ['dry_run' => false]);

    expect($applied['dry_run'])->toBeFalse()
        ->and($applied['total_deleted'])->toBe(1)
        ->and(Storage::disk('local')->files('backups'))->toHaveCount(2);
});

it('hands over a setup guide and the configuration reference', function (): void {
    $guide = mcpTool('backup_setup_guide')['content'][0]['text'];

    expect($guide)->toContain('backup_configure')->toContain('off-site');

    $reference = mcpData('backup_config_reference', ['search' => 'retention']);
    $keys = array_column($reference['options'], 'key');

    expect($keys)->toContain('retention.strategy', 'retention.simple.keep_latest')
        ->and($keys)->not->toContain('compression.algorithm');
});

it('documents every key the shipped config file contains', function (): void {
    $reference = new ConfigReference;
    $config = require dirname(__DIR__, 2).'/config/backup-manager.php';
    $undocumented = [];

    $walk = function (array $values, string $prefix) use (&$walk, $reference, &$undocumented): void {
        foreach ($values as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $lookup = (string) preg_replace('/^profiles\.[^.]+/', 'profiles.*', $path);

            if ($reference->find($lookup) !== null) {
                continue;
            }

            if (is_array($value) && $value !== [] && ! array_is_list($value)) {
                $walk($value, $path);

                continue;
            }

            $undocumented[] = $path;
        }
    };

    $walk($config, '');

    expect($undocumented)->toBe([]);
});
