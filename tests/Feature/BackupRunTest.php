<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Nyoncode\BackupManager\Managers\BackupManager;
use Nyoncode\BackupManager\ValueObjects\Manifest;

beforeEach(function (): void {
    $this->work = sys_get_temp_dir().'/bm-run-'.uniqid();
    $this->filesDir = "{$this->work}/site";
    $this->dbFile = "{$this->work}/database.sqlite";

    mkdir("{$this->filesDir}/config", 0777, true);
    file_put_contents("{$this->filesDir}/index.php", '<?php echo "app";');
    file_put_contents("{$this->filesDir}/config/app.php", 'return [];');
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
            'files' => ['include' => [$this->filesDir], 'exclude' => []],
            'destinations' => ['local'],
            'path' => 'backups',
        ],
    ]);

    Storage::fake('local');
});

function manifestNameFor(string $archive): string
{
    return str_replace('.tar.gz', '.manifest.json', $archive);
}

it('creates a full backup with database, files and a manifest on the destination disk', function (): void {
    DB::connection('bm_sqlite')->statement('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    DB::connection('bm_sqlite')->statement("INSERT INTO users (name) VALUES ('Ada')");

    $result = app(BackupManager::class)->run();

    expect($result->profile)->toBe('test')
        ->and($result->archiveFileName)->toEndWith('.tar.gz')
        ->and($result->destinations)->toBe(['local']);

    $disk = Storage::disk('local');
    $disk->assertExists("backups/{$result->archiveFileName}");
    $disk->assertExists('backups/'.manifestNameFor($result->archiveFileName));

    $manifest = Manifest::fromJson($disk->get('backups/'.manifestNameFor($result->archiveFileName)));

    expect($manifest->name)->toBe('Acme')
        ->and($manifest->databases)->toHaveCount(1)
        ->and($manifest->databases[0]->connection)->toBe('bm_sqlite')
        ->and($manifest->fileCount())->toBeGreaterThanOrEqual(3)
        ->and($manifest->compression->value)->toBe('gzip')
        ->and($manifest->archiveChecksum->matchesFile(
            Storage::disk('local')->path('backups/'.$result->archiveFileName)
        ))->toBeTrue();
});

it('cleans up the temporary workspace after a run', function (): void {
    DB::connection('bm_sqlite')->statement('CREATE TABLE t (id INTEGER PRIMARY KEY)');

    app(BackupManager::class)->run();

    expect(glob("{$this->work}/temp/*"))->toBe([]);
});
