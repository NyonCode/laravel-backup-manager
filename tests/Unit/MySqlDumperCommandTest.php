<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Database\Dumpers\MySqlDumper;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Support\BinaryLocator;

function invokePrivate(object $object, string $method, array $args): mixed
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);

    return $reflection->invoke($object, ...$args);
}

function mysqlTarget(): DatabaseTarget
{
    return DatabaseTarget::fromConnection('mysql', [
        'driver' => 'mysql',
        'host' => 'db',
        'port' => 3307,
        'database' => 'acme',
        'username' => 'app',
        'password' => 'secret',
    ], excludeTables: ['telescope_entries'], schemaOnlyTables: ['sessions']);
}

it('builds a safe, portable base dump command', function (): void {
    $dumper = new MySqlDumper(new BinaryLocator);

    $command = invokePrivate($dumper, 'baseCommand', ['mysqldump', mysqlTarget(), ['telescope_entries', 'sessions']]);

    expect($command)->toContain('--single-transaction')
        ->toContain('--skip-lock-tables')
        ->toContain('--routines')
        ->toContain('--host=db')
        ->toContain('--port=3307')
        ->toContain('--user=app')
        ->toContain('--ignore-table=acme.telescope_entries')
        ->toContain('--ignore-table=acme.sessions')
        // password must never appear on the command line
        ->not->toContain('secret')
        ->not->toContain('--password=secret');

    // database name is the final argument
    expect(end($command))->toBe('acme');
});

it('keeps DROP TABLE in the schema-only pass so restores are idempotent', function (): void {
    $dumper = new MySqlDumper(new BinaryLocator);

    $command = invokePrivate($dumper, 'schemaOnlyCommand', ['mysqldump', mysqlTarget()]);

    // Regression guard: --skip-add-drop-table breaks restore onto an existing schema.
    expect($command)->toContain('--no-data')
        ->toContain('sessions')
        ->not->toContain('--skip-add-drop-table');
});

it('resolves mariadb through the mysql dumper driver', function (): void {
    $target = DatabaseTarget::fromConnection('maria', ['driver' => 'mariadb', 'database' => 'x']);

    expect($target->driver)->toBe(DatabaseDriver::MariaDb);
});
