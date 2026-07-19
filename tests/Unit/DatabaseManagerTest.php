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

it('dumps and restores an sqlite database as a byte-for-byte copy', function (): void {
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

    expect(file_get_contents($dumpPath))->toBe(file_get_contents($sourceDb));

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
