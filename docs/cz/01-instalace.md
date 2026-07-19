# Instalace

Backup Manager se instaluje jako každý jiný Laravel balíček: nainstalujete ho
Composerem, publikujete konfigurační soubor a hned můžete spustit první zálohu.
Tato stránka vás provede požadavky a prvními kroky.

## Požadavky

Než začnete, ověřte, že vaše prostředí splňuje to nejdůležitější:

| Požadavek | Verze |
|-----------|-------|
| PHP | 8.3+ |
| Laravel | 12.x / 13.x |
| Rozšíření | `ext-openssl`, `ext-json` (součást PHP) |
| Volitelné | `ext-bz2` (bzip2), binárka `zstd` (komprese zstd) |

Zálohy databází spouštějí nativní příkazové nástroje výrobce databáze, takže
potřebujete jen ty pro databáze, které skutečně používáte:

| Ovladač | Potřebná binárka |
|---------|------------------|
| MySQL / MariaDB | `mysqldump`, `mysql` (obnova) |
| PostgreSQL | `pg_dump`, `psql` (obnova) |
| SQLite | žádná (kopie souboru) |

## Instalace

```bash
composer require nyoncode/laravel-backup-manager
```

Laravel service provider objeví automaticky, takže není potřeba nic ručně
registrovat. Dále publikujte konfigurační soubor, abyste si ho mohli přizpůsobit:

```bash
php artisan vendor:publish --tag="backup-manager-config"
```

Vznikne soubor `config/backup-manager.php` — jediné místo, kde žijí všechny
volby.

### Historie operací (volitelné)

Backup Manager umí ukládat záznam o každém běhu do databáze, což se hodí pro audit
nebo později pro dashboard. Tento krok je volitelný; zapnete ho tak, že
publikujete a spustíte migraci:

```bash
php artisan vendor:publish --tag="backup-manager-migrations"
php artisan migrate
```

Vznikne tabulka `backup_history`. Pokud migraci přeskočíte, operace se stále
logují; DB historii explicitně vypnete nastavením `monitoring.store_history` na
`false`.

## Ověření

Ověřte, že je vše propojené — vytvořte zálohu a vypište, co se uložilo:

```bash
php artisan backup:run
php artisan backup:list
```

Pokud `mysqldump`/`pg_dump` nejsou v `PATH`, nastavte absolutní cesty pod klíčem
`binaries` — viz [Konfigurace](02-konfigurace.md#binarky).

## Proměnné prostředí

Nechcete upravovat konfigurační soubor přímo? Většinu nastavení lze řídit rovnou
z `.env`. Tady jsou ty, po kterých sáhnete nejčastěji:

```dotenv
BACKUP_PROFILE=default
BACKUP_COMPRESSION=gzip
BACKUP_ENCRYPTION=none          # aes-256-cbc | aes-256-gcm
BACKUP_ENCRYPTION_KEY=          # base64:... (32 bajtů) když je šifrování zapnuté
BACKUP_RETENTION=gfs            # gfs | simple
BACKUP_SCHEDULE=false
BACKUP_QUEUE=false
```

Kompletní seznam viz [Konfigurace](02-konfigurace.md).
