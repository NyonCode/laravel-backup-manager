<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\Managers\RestoreManager;
use Nyoncode\BackupManager\Tests\Integration\DatabaseTestKit;
use Nyoncode\BackupManager\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Integration');

/**
 * Drives a full, real backup → data-loss → restore round-trip against a live
 * database, asserting the data comes back and a schema-only table is emptied.
 *
 * Used by the integration suite; portable across engines via the schema builder.
 *
 * @param  array<string, mixed>  $connection  A Laravel database connection config.
 * @param  array{label: string, compression: string, encryption: string}  $options
 */
function assertDatabaseRoundtrip(array $connection, array $options): void
{
    $work = sys_get_temp_dir().'/bm-int-'.uniqid();
    @mkdir($work, 0777, true);
    Storage::fake('local');

    config()->set('database.connections.integration', $connection);
    config()->set('database.default', 'integration');

    foreach (DatabaseTestKit::binaries() as $binary => $path) {
        config()->set("backup-manager.binaries.{$binary}", $path);
    }

    config()->set('backup-manager.default', 'integration');
    config()->set('backup-manager.working_directory', "{$work}/temp");
    config()->set('backup-manager.compression.algorithm', $options['compression']);
    config()->set('backup-manager.encryption.algorithm', $options['encryption']);

    if ($options['encryption'] !== 'none') {
        config()->set('backup-manager.encryption.key', 'base64:'.base64_encode(random_bytes(32)));
    }

    config()->set('backup-manager.profiles', [
        'integration' => [
            'name' => $options['label'],
            'type' => 'database',
            'databases' => ['connections' => ['integration'], 'schema_only_tables' => ['bm_logs']],
            'files' => ['include' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    // Seed a portable schema: a data table and a schema-only table.
    $schema = Schema::connection('integration');
    $schema->dropIfExists('bm_logs');
    $schema->dropIfExists('bm_users');
    $schema->create('bm_users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->nullable();
    });
    $schema->create('bm_logs', function (Blueprint $table): void {
        $table->id();
        $table->string('message');
    });

    DB::connection('integration')->table('bm_users')->insert([
        ['name' => 'Ada Lovelace', 'email' => 'ada@acme.test'],
        ['name' => 'Linus Torvalds', 'email' => 'linus@acme.test'],
        ['name' => 'Grace Hopper', 'email' => 'grace@acme.test'],
    ]);
    DB::connection('integration')->table('bm_logs')->insert([['message' => 'x'], ['message' => 'y']]);

    // Back up.
    $result = app(BackupManager::class)->run();

    $manifestDriver = $result->manifest->databases[0]->driver->value;
    expect($manifestDriver)->toBe($connection['driver'])
        ->and(Storage::disk('local')->exists("backups/{$result->archiveFileName}"))->toBeTrue();

    // Data loss.
    $schema->drop('bm_users');
    DB::connection('integration')->table('bm_logs')->delete();

    // Restore.
    $backup = app(BackupRepository::class)->list('local', 'backups')[0];
    app(RestoreManager::class)->restore($backup, RestoreOptions::databasesOnly());
    DB::purge('integration');

    expect(DB::connection('integration')->table('bm_users')->count())->toBe(3)
        ->and(DB::connection('integration')->table('bm_users')->where('name', 'Ada Lovelace')->exists())->toBeTrue()
        ->and(Schema::connection('integration')->hasTable('bm_logs'))->toBeTrue()
        ->and(DB::connection('integration')->table('bm_logs')->count())->toBe(0); // schema only

    // Clean up the test tables.
    $schema->dropIfExists('bm_logs');
    $schema->dropIfExists('bm_users');
}
