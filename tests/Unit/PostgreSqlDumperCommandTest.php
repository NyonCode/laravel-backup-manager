<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Database\Dumpers\PostgreSqlDumper;
use Nyoncode\BackupManager\DTOs\DatabaseTarget;
use Nyoncode\BackupManager\Support\BinaryLocator;

function pgCommand(): array
{
    $target = DatabaseTarget::fromConnection('pgsql', [
        'driver' => 'pgsql',
        'host' => 'db',
        'port' => 5432,
        'database' => 'acme',
        'username' => 'app',
        'password' => 'secret',
    ], excludeTables: ['telescope_entries'], schemaOnlyTables: ['sessions']);

    $dumper = new PostgreSqlDumper(new BinaryLocator);
    $method = new ReflectionMethod($dumper, 'command');
    $method->setAccessible(true);

    return $method->invoke($dumper, 'pg_dump', $target);
}

it('builds a safe, idempotent pg_dump command', function (): void {
    $command = pgCommand();

    expect($command)->toContain('--no-owner')
        ->toContain('--no-privileges')
        ->toContain('--clean')       // drop objects before recreate → idempotent restore
        ->toContain('--if-exists')
        ->toContain('--host=db')
        ->toContain('--port=5432')
        ->toContain('--username=app')
        ->toContain('--exclude-table=telescope_entries')
        ->toContain('--exclude-table-data=sessions')  // schema kept, data skipped
        ->not->toContain('secret')                    // password never on the CLI
        ->not->toContain('--password=secret');

    expect(end($command))->toBe('acme'); // database is the final argument
});
