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

## Kam se soubory obnovují

Soubory se zapisují zpět na **absolutní cesty zaznamenané v manifestu**. Ve
výchozím stavu je obnova omezena na *aktuální* cesty `files.include` profilu:
leží-li některý soubor mimo ně, obnova se odmítne dřív, než se cokoli změní, a
vypíšou se problematické cesty.

```text
Restore refused before changing anything: 2 file(s) in this backup lie outside the profile's current files.include paths (/etc/cron.d/backup, /var/www/old-app/.env). Either the manifest was tampered with, or the include paths changed since the backup (e.g. the app moved). If you trust this backup, set backup-manager.restore.confine_to_include_paths to false for this restore.
```

Typickou příčinou je zmanipulovaný manifest nebo přesun aplikace na jinou cestu
na serveru. Chcete-li důvěryhodnou zálohu obnovit na zaznamenané cesty, vypněte
kontrolu jen pro tu jednu obnovu:

```bash
php artisan backup:restore --allow-outside          # this restore only
BACKUP_RESTORE_CONFINE=false php artisan backup:restore
```

```php
'restore' => [
    'confine_to_include_paths' => (bool) env('BACKUP_RESTORE_CONFINE', true),
],
```

> Nechte ji zapnutou. Manifestu se nedůvěřuje, konfiguraci ano. Asistent tento
> klíč přes [MCP](14-mcp.md) změnit nemůže.

Zaznamenávají se i oprávnění souborů (schéma manifestu 1.3, `files[].mode`) a
s každým souborem se obnovují. Zálohy vytvořené před 1.3 mód zaznamenaný nemají
a obnoví se s výchozími oprávněními.

## Nepodepsané dumpy databází

`mysql` a `psql` spouštějí víc než SQL — shellové příkazy, čtení a zápis
souborů — a žádná kontrola textu dumpu nevyloučí každou cestu, kterou by se k
nim podvržený dump dostal. Dump databáze se proto obnoví, jen když se mu dá
věřit:

- **záloha je podepsaná** (Pro, `BACKUP_SIGNING=true`) a podpis sedí —
  podvržený dump se sem vůbec nedostane; nebo
- **klient se sám uzamkne**: MariaDB 10.5.25+ / 11.x běží s `--sandbox`, MySQL
  8.4.6+ s `--commands=OFF` a psql 13.22, 14.19, 15.14, 16.10, 17.6 a novější
  spustí dump uvnitř `\restrict` s klíčem, který zná jen obnova. Každý klientský
  příkaz pak odmítne sám klient.

Jinak se obnova odmítne dřív, než se cokoli změní. U zálohy, které věříte, to
jednorázově přebijete:

```bash
php artisan backup:restore --trust-unsigned-dump
```

`BACKUP_RESTORE_TRUST_UNSIGNED_DUMPS=true` totéž nastaví trvale; asistent to
přes MCP nastavit nemůže. Obnovy jen souborů se to netýká.

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

Pokud selže samotný snapshot — pre-restore dump databáze nebo kopie souboru,
který by se přepsal — obnova se **přeruší dřív, než cokoli změní**, a vyhodí
`RestoreException`. Pokračovat by znamenalo přepsat živá data bez cesty zpět.
Jedinou výjimkou je databáze, která zatím neexistuje: není kam se vracet, proto
se přeskočí a zapíše do logu. `--unsafe` (níže) je záměrné vypnutí.

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

Před spuštěním klienta se dump zkontroluje. Dump, který nese příkazy klienta
`mysql`/`psql` (`\!`, `system`, `source`, …), se odmítne s `RestoreException` —
viz [Zabezpečení](06-zabezpeceni.md#zpřísnění-v-13).
