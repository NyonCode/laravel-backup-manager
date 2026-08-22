<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Support\MigrationProbe;
use Nyoncode\BackupManager\ValueObjects\Checksum;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\MigrationState;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-mig-'.uniqid();
    $this->filesDir = "{$this->work}/site";

    mkdir("{$this->filesDir}/config", 0777, true);
    file_put_contents("{$this->filesDir}/index.php", '<?php echo "app";');

    config()->set('backup-manager.default', 'test');
    config()->set('backup-manager.working_directory', "{$this->work}/temp");
    config()->set('backup-manager.compression.algorithm', 'gzip');
    config()->set('backup-manager.encryption.algorithm', 'none');
    config()->set('backup-manager.profiles', [
        'test' => [
            'name' => 'Acme',
            'type' => 'files',
            'databases' => ['connections' => []],
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
});

function seedMigrations(array $names): void
{
    Schema::dropIfExists('migrations');
    Schema::create('migrations', function ($table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });

    foreach ($names as $i => $name) {
        DB::table('migrations')->insert(['migration' => $name, 'batch' => $i + 1]);
    }
}

function storedManifest(): Manifest
{
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];

    return app(BackupRepository::class)->readManifest($backup);
}

it('records the migrations that had run, in order', function (): void {
    seedMigrations(['2024_01_01_000000_create_users', '2024_02_01_000000_add_roles']);

    app(BackupManager::class)->run('test');

    expect(storedManifest()->migrations?->ran)->toBe([
        '2024_01_01_000000_create_users',
        '2024_02_01_000000_add_roles',
    ]);
});

it('stamps the manifest schema version that carries the field', function (): void {
    seedMigrations(['2024_01_01_000000_create_users']);

    app(BackupManager::class)->run('test');

    expect(storedManifest()->schemaVersion)->toBe('1.2');
});

/**
 * Provenance is worth having and never worth failing a backup over.
 */
it('still backs up when the migrations table does not exist', function (): void {
    Schema::dropIfExists('migrations');

    app(BackupManager::class)->run('test');

    expect(storedManifest()->migrations)->toBeNull();
});

it('still backs up when the database is unreachable', function (): void {
    config()->set('database.default', 'broken');
    config()->set('database.connections.broken', [
        'driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 1,
        'database' => 'nope', 'username' => 'nope', 'password' => 'nope',
    ]);

    app(BackupManager::class)->run('test');

    expect(storedManifest()->migrations)->toBeNull();
});

it('reads the migrations table name from the database config', function (): void {
    Schema::dropIfExists('migrations');
    Schema::dropIfExists('schema_log');
    Schema::create('schema_log', function ($table): void {
        $table->increments('id');
        $table->string('migration');
        $table->integer('batch');
    });
    DB::table('schema_log')->insert(['migration' => '2024_01_01_000000_create_users', 'batch' => 1]);

    config()->set('database.migrations', 'schema_log');

    expect(app(MigrationProbe::class)->capture()?->ran)->toBe(['2024_01_01_000000_create_users']);
});

/**
 * Laravel 11 allows the config array form; the probe reads it the same way the
 * framework's own migrator does.
 */
it('reads the table name from the array config form', function (): void {
    seedMigrations(['2024_01_01_000000_create_users']);
    config()->set('database.migrations', ['table' => 'migrations', 'update_date_on_publish' => true]);

    expect(app(MigrationProbe::class)->capture()?->ran)->toBe(['2024_01_01_000000_create_users']);
});

it('carries the schema state through signing and unsigning', function (): void {
    seedMigrations(['2024_01_01_000000_create_users']);
    app(BackupManager::class)->run('test');

    $manifest = storedManifest();
    $signed = $manifest->withSignature(new Checksum(ChecksumAlgorithm::Sha256, str_repeat('a', 64)));

    // The signer wraps an already-built manifest, so a field it forgets to copy
    // would vanish from every signed backup.
    expect($signed->migrations?->ran)->toBe(['2024_01_01_000000_create_users'])
        ->and($signed->withoutSignature()->migrations?->ran)->toBe(['2024_01_01_000000_create_users']);
});

it('reads back a manifest written before the field existed', function (): void {
    seedMigrations(['2024_01_01_000000_create_users']);
    app(BackupManager::class)->run('test');

    $data = storedManifest()->toArray();
    unset($data['migrations']);
    $data['schema_version'] = '1.1';

    expect(Manifest::fromArray($data)->migrations)->toBeNull();
});

it('lets two backups be diffed against each other', function (): void {
    seedMigrations(['2024_01_01_000000_create_users']);
    app(BackupManager::class)->run('test');
    $before = storedManifest()->migrations;

    $after = new MigrationState([
        '2024_01_01_000000_create_users',
        '2024_03_01_000000_add_billing',
    ]);

    expect($before)->not->toBeNull()
        ->and($after->ahead($before))->toBe(['2024_03_01_000000_add_billing'])
        ->and($after->matches($before))->toBeFalse();
});
