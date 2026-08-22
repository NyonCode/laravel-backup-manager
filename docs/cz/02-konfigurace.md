# Konfigurace

Všechny volby žijí v `config/backup-manager.php`. Tato stránka jimi projde sekci
po sekci. Výchozí hodnoty jsou rozumné, takže většinu souboru můžete nechat být a
změnit jen to, co potřebujete.

## Profily

**Profil** je samostatná definice zálohy — *co* zálohovat a *kam* to poslat.
Můžete jich mít libovolně; klíč `default` rozhoduje, který se spustí, když žádný
výslovně neurčíte.

```php
'default' => env('BACKUP_PROFILE', 'default'),

'profiles' => [
    'default' => [
        'name' => env('APP_NAME', 'Laravel'),
        'type' => 'full',                 // full | database | files
        'databases' => [
            'connections' => [],          // [] = výchozí připojení
            'exclude_tables' => [],
            'schema_only_tables' => [],
        ],
        'files' => [
            'include' => [base_path()],
            'exclude' => [base_path('vendor'), base_path('node_modules'), base_path('.git')],
            'follow_symlinks' => false,
            'include_hidden' => true,
        ],
        'destinations' => ['local'],       // názvy Laravel disků
        'path' => 'backups',               // podadresář na každém disku
        'retention' => null,               // null = použít sdílenou retenci
    ],
],
```

Můžete přidat libovolný počet profilů, například malou častou zálohu databáze a
plnou noční:

```php
'profiles' => [
    'database' => ['type' => 'database', 'destinations' => ['s3']],
    'nightly'  => ['type' => 'full', 'destinations' => ['s3', 'local']],
],
```

Konkrétní profil spustíte přes `php artisan backup:run --profile=nightly`.

## Komprese

```php
'compression' => [
    'algorithm' => env('BACKUP_COMPRESSION', 'gzip'),  // none | gzip | bzip2 | zstd
    'level' => (int) env('BACKUP_COMPRESSION_LEVEL', 6),
],
```

`gzip` a `bzip2` jsou čistě v PHP a vždy dostupné. `zstd` vyžaduje binárku
`zstd`. Viz [Soubory](04-soubory.md#komprese).

## Šifrování

```php
'encryption' => [
    'algorithm' => env('BACKUP_ENCRYPTION', 'none'),   // none | aes-256-cbc | aes-256-gcm
    'key' => env('BACKUP_ENCRYPTION_KEY'),             // 32 bajtů, base64: akceptováno
],
```

Podrobnosti a kompromisy viz [Zabezpečení](06-zabezpeceni.md#sifrovani).

## Integrita

Každá záloha dostane kontrolní součet, abyste později prokázali, že nebyla
poškozena; volitelně může být i podepsaná (podpis je funkce Pro):

```php
'integrity' => [
    'checksum' => env('BACKUP_CHECKSUM', 'sha256'),    // sha256 | sha512
    'signing' => [
        'enabled' => (bool) env('BACKUP_SIGNING', false),
        'key' => env('BACKUP_SIGNING_KEY'),            // výchozí = šifrovací klíč
    ],
],
```

## Retence

```php
'retention' => [
    'strategy' => env('BACKUP_RETENTION', 'gfs'),      // gfs | simple
    'gfs' => ['keep_daily' => 7, 'keep_weekly' => 4, 'keep_monthly' => 6, 'keep_yearly' => 2],
    'simple' => ['keep_latest' => 10],
    'max_age_days' => 365,
    'max_storage_megabytes' => null,
],
```

Viz [Retence](08-retence.md).

## Fronty (Queue)

Spouštějte zálohy na pozadí přes Laravel frontu, místo aby blokovaly konzolový
příkaz nebo request, který je spustil:

```php
'queue' => [
    'enabled' => (bool) env('BACKUP_QUEUE', false),
    'connection' => env('BACKUP_QUEUE_CONNECTION'),
    'queue' => env('BACKUP_QUEUE_NAME', 'backups'),
    'timeout' => (int) env('BACKUP_QUEUE_TIMEOUT', 3600),
],
```

## Plánování

```php
'schedule' => [
    'enabled' => (bool) env('BACKUP_SCHEDULE', false),
    'backup' => '0 2 * * *',
    'cleanup' => '0 4 * * *',
    'monitor' => '0 6 * * *',
],
```

Při zapnutí balíček tyto příkazy automaticky zaregistruje do Laravel Scheduleru.
Viz [Plánování a monitoring](10-planovani.md).

## Monitoring a notifikace

Řídí kontrolu zdraví a to, kdo se dozví, když záloha selže nebo se cíl začne
tvářit nezdravě:

```php
'monitoring' => [
    'max_backup_age_hours' => 25,
    'store_history' => true,
    'notifications' => [
        'enabled' => true,
        'on' => ['failure', 'unhealthy'],   // success | failure | unhealthy
        'mail' => ['to' => []],
        'slack' => ['webhook_url' => env('BACKUP_NOTIFY_SLACK')],
    ],
],
```

## Binárky

Ponechte `null` pro automatické vyhledání v `PATH`, nebo zadejte absolutní cesty:

```php
'binaries' => [
    'mysqldump' => env('BACKUP_MYSQLDUMP_PATH'),
    'mysql' => env('BACKUP_MYSQL_PATH'),
    'pg_dump' => env('BACKUP_PGDUMP_PATH'),
    'psql' => env('BACKUP_PSQL_PATH'),
    'zstd' => env('BACKUP_ZSTD_PATH'),
],
```

## Časové limity a pracovní adresář

```php
'timeout' => ['database' => 3600, 'process' => 3600],  // sekundy; null = bez limitu
'working_directory' => storage_path('app/backup-manager/temp'),
```

Pracovní adresář musí mít dost volného místa pro největší zálohu během jejího
sestavení, komprese a šifrování před nahráním.

## AI asistenti (MCP)

```php
'mcp' => [
    'enabled' => (bool) env('BACKUP_MCP', true),
    'allow_writes' => (bool) env('BACKUP_MCP_ALLOW_WRITES', true),
    'allow_destructive' => (bool) env('BACKUP_MCP_ALLOW_DESTRUCTIVE', false),
    'environments' => ['local'],   // ['*'] povolí všechna prostředí
],
```

Co smí asistent přes `php artisan backup:mcp`. Čtení a spouštění záloh je
povolené vždy, zápis konfigurace je ve výchozím stavu zapnutý, **mazání záloh je
vypnuté**, dokud ho nepovolíte, a obnova se nenabízí vůbec. Mimo uvedená
prostředí server odmítne naběhnout. Viz [AI asistenti (MCP)](14-mcp.md).
