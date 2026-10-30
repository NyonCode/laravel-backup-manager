---
title: Retence
description: "Bez zásahu se zálohy hromadí, až zaplní disk. Retence je politika, která rozhoduje, které historické zálohy ponechat, a zbytek odstraní."
key: retention
---
# Retence

Bez zásahu se zálohy hromadí, až zaplní disk. **Retence** je politika, která
rozhoduje, které historické zálohy ponechat, a zbytek odstraní. Aplikujete ji
příkazem `php artisan backup:clean`, ručně nebo v plánovači.

```php
'retention' => [
    'strategy' => 'gfs',        // gfs | simple
    'gfs' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_yearly' => 2],
    'simple' => ['keep_latest' => 10],
    'max_age_days' => 365,
    'max_storage_megabytes' => null,
],
```

## Strategie

### GFS (Grandfather-Father-Son)

> **Funkce Pro.** GFS retence vyžaduje [Backup Manager Pro](13-pro-a-ui.md). Free
> jádro nabízí `simple`; politika s `gfs` bez Pro raději nesmaže nic
> (fail-closed), než aby použila jinou strategii.

Ponechává nejnovější zálohu z každého z posledních N **dní**, **týdnů**, **měsíců**
a **let**, čímž vytváří zužující se historii — hustou nedávnou, řídkou starou. S
výchozími hodnotami výše ponecháte posledních 7 dní, poté jednu za týden po dobu
4 týdnů, jednu za měsíc po 6 měsíců a jednu za rok po 2 roky.

### Jednoduchá (simple)

Ponechá N nejnovějších záloh a zbytek smaže:

```php
'retention' => ['strategy' => 'simple', 'simple' => ['keep_latest' => 10]],
```

## Další limity

Aplikují se nad zvolenou strategií:

- **`max_age_days`** — nic staršího se neponechá (`null` vypíná).
- **`max_storage_megabytes`** — jakmile by ponechaná sada překročila tuto celkovou
  velikost, zahazují se nejstarší ponechané zálohy, dokud se nevejde (`null`
  vypíná). **(Pro** — free jádro strop velikosti nevynucuje.)

## Přepsání per profil

Profil může přepsat retenci:

```php
'profiles' => [
    'hourly' => [
        'type' => 'database',
        'retention' => ['strategy' => 'simple', 'simple' => ['keep_latest' => 48]],
    ],
],
```

## Náhled

Použijte `--dry-run` k zobrazení toho, co by se smazalo, bez mazání:

```bash
php artisan backup:clean --dry-run
php artisan backup:clean --profile=nightly --disk=s3
```

Retence se aplikuje na každý cíl profilu (nebo jen na `--disk`).

## Retence platí jen pro svůj profil

`path` má pro každý profil výchozí hodnotu `backups`, takže dva profily zapisující
na stejný disk sdílejí adresář — to je běžný stav, ne výjimka. Politika každého
profilu řídí **jen jeho vlastní zálohy**: jen ty se počítají do `keep_latest`,
poměřují s limity a mažou.

```bash
php artisan backup:clean                 # politika výchozího profilu, jen jeho zálohy
php artisan backup:clean --profile=full  # politika profilu full, jen jeho zálohy
```

Zálohy se rozlišují podle profilu zapsaného v manifestu, takže se na disku nic
přesouvat nemusí. Záloha, jejíž manifest tenhle údaj ještě nemá — nebo nejde
přečíst — se počítá jako záloha ptajícího se profilu, takže po upgradu se uklízí
přesně to, co se uklízelo dřív. Pokud nechcete sdílet adresář vůbec, dejte
profilu vlastní `path`.

`backup:list`, `backup:verify` i `backup:restore` mají stejný rozsah: co profil
vypíše, to jeho retence řídí.

## Programové použití

Celá politika přes všechny cíle profilu:

```php
use Nyoncode\BackupManager\Managers\CleanupManager;
use Nyoncode\BackupManager\Support\ProfileResolver;

$definition = app(ProfileResolver::class)->resolve('nightly');
$reports = app(CleanupManager::class)->clean($definition, dryRun: true);

foreach ($reports as $report) {
    echo "{$report->disk}: {$report->deletedCount()} záloh(y)\n";
}
```

Nebo jeden engine na jednom cíli — profil předejte, jinak se berou v úvahu
všechny zálohy v cestě:

```php
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Managers\RetentionManager;

$policy = RetentionPolicy::fromConfig(config('backup-manager.retention'));
$deleted = app(RetentionManager::class)->apply('s3', 'backups', $policy, dryRun: true, profile: 'nightly');
```
