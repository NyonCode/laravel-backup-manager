<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Database\Restorers\MySqlRestorer;
use Nyoncode\BackupManager\Database\Restorers\PostgreSqlRestorer;
use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Support\BinaryLocator;

it('counts a mysql client as locked down only when every client command can be switched off', function (string $help, bool $locked): void {
    $binary = fakeClient('mysql', $help);
    $restorer = new MySqlRestorer(new BinaryLocator(['mysql' => $binary, 'mariadb' => $binary]));

    expect($restorer->locksDownClient(DatabaseDriver::MySql))->toBe($locked);
})->with([
    'mariadb sandbox' => ['  --sandbox           Disallow commands that access the file system', true],
    'mysql commands' => ['  --commands          Enable or disable processing of local mysql commands.', true],
    'system command only' => ['  --system-command    Enable or disable the system command.', false],
    'nothing' => ['Usage: mysql [OPTIONS] [database]', false],
])->skip(PHP_OS_FAMILY === 'Windows', 'shell scripts');

it('knows which psql releases have \restrict', function (string $version, bool $locked): void {
    $binary = fakeClient('psql', "psql (PostgreSQL) {$version}");
    $restorer = new PostgreSqlRestorer(new BinaryLocator(['psql' => $binary]));

    expect($restorer->locksDownClient(DatabaseDriver::PostgreSql))->toBe($locked);
})->with([
    ['16.9', false],
    ['16.10', true],
    ['17.5', false],
    ['17.6', true],
    ['13.22', true],
    ['12.22', false],
    ['18.0', true],
])->skip(PHP_OS_FAMILY === 'Windows', 'shell scripts');
