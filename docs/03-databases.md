# Databases

Backup Manager backs up your databases by running each engine's own dump tool
(such as `mysqldump`) for you — there's no SQL to write. MySQL, MariaDB,
PostgreSQL and SQLite work out of the box, and you can teach it new engines (see
[Extending](12-extending.md)).

## Supported drivers

| Driver | Config value | Dump tool | Restore tool |
|--------|--------------|-----------|--------------|
| MySQL | `mysql` | `mysqldump`, else `mariadb-dump` | `mysql`, else `mariadb` |
| MariaDB | `mariadb` | `mariadb-dump`, else `mysqldump` | `mariadb`, else `mysql` |
| PostgreSQL | `pgsql` | `pg_dump` | `psql` |
| SQLite | `sqlite` | file copy | file copy |

The driver is taken directly from your Laravel connection config, so no extra
mapping is needed.

MariaDB 11 renamed its command line clients — `mysqldump` became `mariadb-dump`,
`mysql` became `mariadb` — and the old names now survive only as deprecated
symlinks that some distributions and container images no longer ship. Both
spellings are tried, preferring the one that matches the server being backed up,
so a MariaDB host works whichever clients it has. Where both are installed the
matching client wins, because MySQL's `mysqldump` can emit SQL a MariaDB server
will not read back.

## Choosing connections

```php
'databases' => [
    'connections' => ['mysql', 'reporting'],  // [] = the default connection
],
```

An empty array backs up `config('database.default')`.

## Excluding tables

```php
'databases' => [
    'exclude_tables' => ['telescope_entries', 'failed_jobs'],
    'schema_only_tables' => ['sessions', 'cache'],
],
```

- **`exclude_tables`** — omitted entirely (no structure, no data).
- **`schema_only_tables`** — structure is dumped, rows are not. Ideal for large,
  regenerable tables (sessions, cache, telescope). The structure is still dumped
  with `DROP TABLE IF EXISTS` so restores remain idempotent.

## Security of credentials

Passwords are **never** placed on the command line (where they would be visible
in the host's process list). They are passed through the environment:

- MySQL/MariaDB → `MYSQL_PWD`
- PostgreSQL → `PGPASSWORD`

## PostgreSQL client version

Use a `pg_dump` / `psql` whose version is **less than or equal to** the server
version. A newer client emits configuration settings (e.g. `transaction_timeout`,
added in PostgreSQL 17) that an older server rejects when the dump is restored.
When in doubt, point the binaries at a version-matched client:

```php
'binaries' => [
    'pg_dump' => '/usr/lib/postgresql/16/bin/pg_dump',
    'psql' => '/usr/lib/postgresql/16/bin/psql',
],
```

## How SQLite is handled

SQLite databases are snapshotted with a byte-for-byte file copy, which preserves
the schema, data and pragmas exactly. In-memory databases cannot be backed up.

## Binaries

If `mysqldump` / `pg_dump` are not on the `PATH`, configure absolute paths:

```php
'binaries' => [
    'mysqldump' => '/usr/local/opt/mysql-client/bin/mysqldump',
    'pg_dump' => '/usr/local/opt/postgresql@16/bin/pg_dump',
],
```

## Database-only backups

Set the profile `type` to `database` to skip files entirely:

```php
'profiles' => [
    'db-hourly' => ['type' => 'database', 'destinations' => ['s3']],
],
```
