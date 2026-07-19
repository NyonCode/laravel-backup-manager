# Obnova

Obnova obrací zálohovací pipeline: archiv se stáhne, ověří se jeho integrita vůči
manifestu, poté se dešifruje, dekomprimuje, rozbalí a obnoví.

> Databázová připojení se resolvují z **aktuální** konfigurace prostředí, ne z
> archivovaných hodnot — zálohu tak lze bezpečně obnovit do stagingu nebo na
> čistý stroj.

## Rozsahy obnovy

| Rozsah | Příkaz |
|--------|--------|
| Vše | `php artisan backup:restore` |
| Pouze databáze | `php artisan backup:restore --database` |
| Pouze soubory | `php artisan backup:restore --files` |
| Vybrané adresáře | `php artisan backup:restore --only=/var/www/app/config` |

Vyberte konkrétní zálohu podle názvu, nebo ji vynechte pro obnovu nejnovější:

```bash
php artisan backup:restore                                  # nejnovější
php artisan backup:restore nightly-2026-07-16-020000.tar.gz.enc
php artisan backup:restore --profile=nightly --disk=s3
```

Obnova se ptá na potvrzení (přepisuje data). V automatizaci použijte `--force`.

## Selektivní obnova

Obnovte jen určité adresáře (porovnává se vůči původním absolutním cestám):

```bash
php artisan backup:restore --only=/var/www/app/storage/app/public \
                           --only=/var/www/app/config
```

Selektivní obnova implikuje pouze soubory.

## Ověření integrity

Před rozbalením se kontrolní součet archivu porovná s manifestem. Neshoda
(poškození nebo manipulace) obnovu přeruší s `IntegrityException`. Vypněte jen,
pokud rozumíte riziku:

```bash
php artisan backup:restore --no-verify
```

## Záchytná síť pro rollback

Obnova přepisuje živá data, takže selhání v půlce by mohlo nechat prostředí
nekonzistentní. Proto je obnova **ve výchozím stavu bezpečná** — používá strategii
snapshot + kompenzace (saga):

1. **Před** jakýmkoli zásahem se pořídí *pre-restore snapshot* — dump každé
   dotčené databáze a kopie každého souboru, který se bude přepisovat.
2. Soubory se obnovují zápisem do dočasného souboru a **atomickým přejmenováním**,
   takže pád nikdy nenechá napůl zapsaný soubor.
3. Při **jakémkoli** selhání se snapshot přehraje zpět — databáze i soubory se
   vrátí přesně do původního stavu a nově vytvořené soubory se smažou. Původní
   chyba se pak vyhodí zabalená v `RestoreException`.
4. Při úspěchu se snapshot zahodí.

Snapshot zhruba zdvojnásobí I/O během obnovy. Vypněte ho, když jste si jistí nebo
obnovujete do zahoditelného prostředí:

```bash
php artisan backup:restore --unsafe
```

```php
// Programově je bezpečnost posledním argumentem každého konstruktoru:
RestoreOptions::full(verifyChecksums: true, safe: true);
RestoreOptions::databasesOnly(safe: false);
```

## Programová obnova

```php
use Nyoncode\BackupManager\DTOs\RestoreOptions;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Managers\RestoreManager;

$repository = app(BackupRepository::class);
$backup = $repository->list('s3', 'backups')[0];   // nejnovější

$result = app(RestoreManager::class)->restore($backup, RestoreOptions::full());

$result->restoredDatabases;   // ['mysql']
$result->restoredFileCount;   // 1423
```

Dostupné konstruktory voleb:

```php
RestoreOptions::full();
RestoreOptions::databasesOnly();
RestoreOptions::filesOnly();
RestoreOptions::selective(['/var/www/app/config']);
```

## Co obnova dělá s databázemi

Dump se pošle do klienta výrobce (`mysql`, `psql`) nebo se u SQLite soubor
zkopíruje zpět. Dumpy MySQL/PostgreSQL obsahují `DROP TABLE IF EXISTS`, takže
obnova nad existujícím schématem je idempotentní.
