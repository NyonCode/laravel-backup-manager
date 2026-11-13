<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Database\Dumpers\MySqlDumper;
use Nyoncode\BackupManager\Database\Dumpers\PostgreSqlDumper;
use Nyoncode\BackupManager\Database\Dumpers\SqliteDumper;
use Nyoncode\BackupManager\Database\Restorers\MySqlRestorer;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;
use Nyoncode\BackupManager\Managers\DatabaseManager;
use Nyoncode\BackupManager\Support\BinaryLocator;

function databaseManager(): DatabaseManager
{
    return new DatabaseManager(new BinaryLocator);
}

it('resolves a dumper per driver, mapping mariadb onto the mysql dumper', function (): void {
    $manager = databaseManager();

    expect($manager->dumper(DatabaseDriver::MySql))->toBeInstanceOf(MySqlDumper::class)
        ->and($manager->dumper(DatabaseDriver::MariaDb))->toBeInstanceOf(MySqlDumper::class)
        ->and($manager->dumper(DatabaseDriver::PostgreSql))->toBeInstanceOf(PostgreSqlDumper::class)
        ->and($manager->dumper(DatabaseDriver::Sqlite))->toBeInstanceOf(SqliteDumper::class)
        ->and($manager->restorer(DatabaseDriver::MariaDb))->toBeInstanceOf(MySqlRestorer::class);
});

it('builds a database target from a laravel connection config', function (): void {
    $target = DatabaseTarget::fromConnection('mysql', [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'acme',
        'username' => 'root',
        'password' => 'secret',
    ], excludeTables: ['telescope_entries']);

    expect($target->driver)->toBe(DatabaseDriver::MySql)
        ->and($target->database)->toBe('acme')
        ->and($target->port)->toBe(3306)
        ->and($target->excludeTables)->toBe(['telescope_entries']);
});

it('dumps and restores an sqlite database as a consistent copy', function (): void {
    $workdir = sys_get_temp_dir().'/bm-'.uniqid();
    mkdir($workdir);
    $sourceDb = "{$workdir}/source.sqlite";

    $pdo = new PDO("sqlite:{$sourceDb}");
    $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)');
    $pdo->exec("INSERT INTO users (name) VALUES ('Ada'), ('Linus')");
    $pdo = null;

    $manager = databaseManager();
    $target = DatabaseTarget::fromConnection('sqlite', ['driver' => 'sqlite', 'database' => $sourceDb]);

    $dumpPath = "{$workdir}/dump.sqlite";
    $manager->dumper(DatabaseDriver::Sqlite)->dump($target, $dumpPath);

    $dumped = new PDO("sqlite:{$dumpPath}");
    expect($dumped->query('SELECT name FROM users ORDER BY id')->fetchAll(PDO::FETCH_COLUMN))->toBe(['Ada', 'Linus']);
    $dumped = null;

    $restoreDb = "{$workdir}/restored.sqlite";
    $restoreTarget = DatabaseTarget::fromConnection('sqlite', ['driver' => 'sqlite', 'database' => $restoreDb]);
    $manager->restorer(DatabaseDriver::Sqlite)->restore($restoreTarget, $dumpPath);

    $restored = new PDO("sqlite:{$restoreDb}");
    $count = (int) $restored->query('SELECT COUNT(*) FROM users')->fetchColumn();

    expect($count)->toBe(2);
});

it('fails clearly when the sqlite source is missing', function (): void {
    $target = DatabaseTarget::fromConnection('sqlite', ['driver' => 'sqlite', 'database' => '/nope/missing.sqlite']);

    databaseManager()->dumper(DatabaseDriver::Sqlite)->dump($target, sys_get_temp_dir().'/out.sqlite');
})->throws(DatabaseDumpException::class);

it('captures writes still in the WAL, which a plain file copy would miss', function (): void {
    $workdir = sys_get_temp_dir().'/bm-wal-'.uniqid();
    mkdir($workdir);
    $sourceDb = "{$workdir}/source.sqlite";

    // A live WAL-mode connection whose last write has not been checkpointed.
    $live = new PDO("sqlite:{$sourceDb}");
    $live->exec('PRAGMA journal_mode=WAL; PRAGMA wal_autocheckpoint=0;');
    $live->exec('CREATE TABLE t (v TEXT)');
    $live->exec("INSERT INTO t VALUES ('in the wal')");

    expect(filesize("{$sourceDb}-wal"))->toBeGreaterThan(0);

    $dumpPath = "{$workdir}/dump.sqlite";
    databaseManager()->dumper(DatabaseDriver::Sqlite)->dump(
        DatabaseTarget::fromConnection('sqlite', ['driver' => 'sqlite', 'database' => $sourceDb]),
        $dumpPath,
    );

    $dumped = new PDO("sqlite:{$dumpPath}");
    expect($dumped->query('SELECT v FROM t')->fetchColumn())->toBe('in the wal');
});

it('drops a stale WAL beside the database it replaces on restore', function (): void {
    $workdir = sys_get_temp_dir().'/bm-walr-'.uniqid();
    mkdir($workdir);
    $dump = "{$workdir}/dump.sqlite";
    (new PDO("sqlite:{$dump}"))->exec("CREATE TABLE t (v TEXT); INSERT INTO t VALUES ('restored');");

    $live = "{$workdir}/live.sqlite";
    file_put_contents($live, 'old');
    file_put_contents("{$live}-wal", 'stale journal of the old file');

    databaseManager()->restorer(DatabaseDriver::Sqlite)->restore(
        DatabaseTarget::fromConnection('sqlite', ['driver' => 'sqlite', 'database' => $live]),
        $dump,
    );

    expect(is_file("{$live}-wal"))->toBeFalse()
        ->and((new PDO("sqlite:{$live}"))->query('SELECT v FROM t')->fetchColumn())->toBe('restored');
});

it('keeps the live database file mode when it swaps a restored one in', function (): void {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Windows has no POSIX file modes');
    }

    $workdir = sys_get_temp_dir().'/bm-own-'.uniqid();
    mkdir($workdir);
    $dump = "{$workdir}/dump.sqlite";
    (new PDO("sqlite:{$dump}"))->exec('CREATE TABLE t (v TEXT)');

    $live = "{$workdir}/live.sqlite";
    (new PDO("sqlite:{$live}"))->exec('CREATE TABLE t (v TEXT)');
    chmod($live, 0660);

    databaseManager()->restorer(DatabaseDriver::Sqlite)->restore(
        DatabaseTarget::fromConnection('sqlite', ['driver' => 'sqlite', 'database' => $live]),
        $dump,
    );

    clearstatcache();
    expect(fileperms($live) & 0777)->toBe(0660);
});
