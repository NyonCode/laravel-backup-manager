# Databáze

Backup Manager zálohuje vaše databáze tak, že za vás spustí dump nástroj daného
enginu (například `mysqldump`) — žádné SQL psát nemusíte. MySQL, MariaDB,
PostgreSQL a SQLite fungují rovnou, a další enginy můžete doplnit (viz
[Rozšíření](12-rozsireni.md)).

## Podporované ovladače

| Ovladač | Hodnota v configu | Nástroj pro zálohu | Nástroj pro obnovu |
|---------|-------------------|--------------------|--------------------|
| MySQL | `mysql` | `mysqldump` | `mysql` |
| MariaDB | `mariadb` | `mysqldump` | `mysql` |
| PostgreSQL | `pgsql` | `pg_dump` | `psql` |
| SQLite | `sqlite` | kopie souboru | kopie souboru |

Ovladač se přebírá přímo z konfigurace Laravel připojení, žádné další mapování
není potřeba.

## Výběr připojení

```php
'databases' => [
    'connections' => ['mysql', 'reporting'],  // [] = výchozí připojení
],
```

Prázdné pole zálohuje `config('database.default')`.

## Vyloučení tabulek

```php
'databases' => [
    'exclude_tables' => ['telescope_entries', 'failed_jobs'],
    'schema_only_tables' => ['sessions', 'cache'],
],
```

- **`exclude_tables`** — zcela vynechány (bez struktury i dat).
- **`schema_only_tables`** — zálohuje se struktura, nikoli řádky. Ideální pro
  velké, znovu-generovatelné tabulky (sessions, cache, telescope). Struktura se
  přesto zálohuje s `DROP TABLE IF EXISTS`, aby byla obnova idempotentní.

## Bezpečnost přihlašovacích údajů

Hesla se **nikdy** neuvádějí na příkazové řádce (kde by byla vidět v seznamu
procesů). Předávají se přes prostředí:

- MySQL/MariaDB → `MYSQL_PWD`
- PostgreSQL → `PGPASSWORD`

## Verze PostgreSQL klienta

Používejte `pg_dump` / `psql`, jejichž verze je **menší nebo rovna** verzi
serveru. Novější klient generuje konfigurační nastavení (např. `transaction_timeout`
přidané v PostgreSQL 17), která starší server při obnově dumpu odmítne. V případě
nejistoty nasměrujte binárky na verzově odpovídajícího klienta:

```php
'binaries' => [
    'pg_dump' => '/usr/lib/postgresql/16/bin/pg_dump',
    'psql' => '/usr/lib/postgresql/16/bin/psql',
],
```

## Jak funguje SQLite

U SQLite se snapshot pořizuje kopií souboru bajt po bajtu, což přesně zachová
schéma, data i pragmy. In-memory databáze zálohovat nelze.

## Binárky

Pokud `mysqldump` / `pg_dump` nejsou v `PATH`, nastavte absolutní cesty:

```php
'binaries' => [
    'mysqldump' => '/usr/local/opt/mysql-client/bin/mysqldump',
    'pg_dump' => '/usr/local/opt/postgresql@16/bin/pg_dump',
],
```

## Zálohy pouze databáze

Nastavte `type` profilu na `database`, aby se soubory zcela přeskočily:

```php
'profiles' => [
    'db-hourly' => ['type' => 'database', 'destinations' => ['s3']],
],
```
