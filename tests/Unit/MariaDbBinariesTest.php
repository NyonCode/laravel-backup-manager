<?php

declare(strict_types=1);

use Nyoncode\BackupManager\Enums\DatabaseDriver;
use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;
use Nyoncode\BackupManager\Support\BinaryLocator;
use Symfony\Component\Process\ExecutableFinder;

/**
 * A host that provides only the named binaries.
 *
 * @param  list<string>  $available
 */
function finderWith(array $available): ExecutableFinder
{
    return new class($available) extends ExecutableFinder
    {
        /** @param list<string> $available */
        public function __construct(private readonly array $available) {}

        public function find(string $name, ?string $default = null, array $extraDirs = []): ?string
        {
            return in_array($name, $this->available, true) ? "/usr/bin/{$name}" : null;
        }
    };
}

/**
 * MariaDB 11 renamed `mysqldump` to `mariadb-dump` and left the old name as a
 * symlink several distributions and container images no longer ship. Asking for
 * `mysqldump` by name is what broke backups on those hosts.
 */
it('dumps a MariaDB host that ships only the mariadb clients', function (): void {
    $locator = new BinaryLocator([], finderWith(['mariadb-dump', 'mariadb']));

    expect($locator->locateFirst(DatabaseDriver::MariaDb->dumpBinaries()))->toBe('/usr/bin/mariadb-dump')
        ->and($locator->locateFirst(DatabaseDriver::MariaDb->clientBinaries()))->toBe('/usr/bin/mariadb');
});

it('still works on a MariaDB host that keeps the old symlinks', function (): void {
    $locator = new BinaryLocator([], finderWith(['mysqldump', 'mysql']));

    expect($locator->locateFirst(DatabaseDriver::MariaDb->dumpBinaries()))->toBe('/usr/bin/mysqldump')
        ->and($locator->locateFirst(DatabaseDriver::MariaDb->clientBinaries()))->toBe('/usr/bin/mysql');
});

it('leaves MySQL hosts on the MySQL clients', function (): void {
    $locator = new BinaryLocator([], finderWith(['mysqldump', 'mysql']));

    expect($locator->locateFirst(DatabaseDriver::MySql->dumpBinaries()))->toBe('/usr/bin/mysqldump')
        ->and($locator->locateFirst(DatabaseDriver::MySql->clientBinaries()))->toBe('/usr/bin/mysql');
});

/**
 * Both clients installed side by side is the case where order matters: MySQL's
 * mysqldump can emit SQL a MariaDB server will not read back.
 */
it('picks the client matching the server when both are installed', function (): void {
    $locator = new BinaryLocator([], finderWith(['mysqldump', 'mysql', 'mariadb-dump', 'mariadb']));

    expect($locator->locateFirst(DatabaseDriver::MariaDb->dumpBinaries()))->toBe('/usr/bin/mariadb-dump')
        ->and($locator->locateFirst(DatabaseDriver::MySql->dumpBinaries()))->toBe('/usr/bin/mysqldump');
});

it('lets a MySQL connection fall back to the MariaDB client', function (): void {
    $locator = new BinaryLocator([], finderWith(['mariadb-dump']));

    expect($locator->locateFirst(DatabaseDriver::MySql->dumpBinaries()))->toBe('/usr/bin/mariadb-dump');
});

it('prefers a configured path over anything on PATH', function (): void {
    $locator = new BinaryLocator(
        ['mariadb-dump' => '/opt/mariadb/bin/mariadb-dump'],
        finderWith(['mysqldump', 'mariadb-dump']),
    );

    expect($locator->locateFirst(DatabaseDriver::MariaDb->dumpBinaries()))
        ->toBe('/opt/mariadb/bin/mariadb-dump');
});

it('names every candidate it tried when none is installed', function (): void {
    $locator = new BinaryLocator([], finderWith([]));

    expect(fn () => $locator->locateFirst(DatabaseDriver::MariaDb->dumpBinaries()))
        ->toThrow(DatabaseDumpException::class, 'The [mariadb-dump] or [mysqldump] binary could not be found');
});

it('keeps Postgres on its own tools', function (): void {
    $locator = new BinaryLocator([], finderWith(['pg_dump', 'psql']));

    expect($locator->locateFirst(DatabaseDriver::PostgreSql->dumpBinaries()))->toBe('/usr/bin/pg_dump')
        ->and($locator->locateFirst(DatabaseDriver::PostgreSql->clientBinaries()))->toBe('/usr/bin/psql');
});

it('has no external tooling for the file-based driver', function (): void {
    expect(DatabaseDriver::Sqlite->dumpBinaries())->toBe([])
        ->and(DatabaseDriver::Sqlite->clientBinaries())->toBe([]);
});
