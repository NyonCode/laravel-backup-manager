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

## Programové použití

```php
use Nyoncode\BackupManager\DTOs\RetentionPolicy;
use Nyoncode\BackupManager\Managers\RetentionManager;

$policy = RetentionPolicy::fromConfig(config('backup-manager.retention'));
$deleted = app(RetentionManager::class)->apply('s3', 'backups', $policy, dryRun: true);
```
