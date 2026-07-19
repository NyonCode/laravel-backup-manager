# Plánování a monitoring

Zálohy se vyplatí, když běží samy a někdo se dozví, když nějaká selže. Tato
stránka pokrývá spouštění záloh podle plánu, jejich odložení do fronty a
sledování jejich zdraví.

## Plánování

Zapněte vestavěné plánování, aby se příkazy automaticky zaregistrovaly do Laravel
Scheduleru:

```php
'schedule' => [
    'enabled' => true,
    'backup' => '0 2 * * *',    // noční záloha ve 02:00
    'cleanup' => '0 4 * * *',   // retence ve 04:00
    'monitor' => '0 6 * * *',   // report zdraví v 06:00
],
```

Nastavte kterýkoli výraz na `null`, aby se daný příkaz neregistroval. Ujistěte se,
že aplikace spouští scheduler:

```text
* * * * * cd /cesta-k-projektu && php artisan schedule:run >> /dev/null 2>&1
```

Chcete si to zapojit sami? Ponechte `schedule.enabled = false` a přidejte příkazy
do `routes/console.php` / scheduleru vaší aplikace jako obvykle.

## Fronty

Dlouho běžící zálohy lze poslat do fronty:

```php
'queue' => [
    'enabled' => true,
    'connection' => env('BACKUP_QUEUE_CONNECTION'),
    'queue' => 'backups',
    'timeout' => 3600,
],
```

Když je zapnuto, `php artisan backup:run` odešle `RunBackupJob` do fronty místo
běhu na místě (musí běžet worker na dané frontě). Synchronní běh vynutíte přes
`--sync`:

```bash
php artisan backup:run          # ve frontě, když queue.enabled = true
php artisan backup:run --sync   # vždy okamžitě
```

Naplánované zálohy jsou registrovány s `withoutOverlapping()`, takže pomalý běh
nikdy nekoliduje s dalším ani s retencí/monitoringem.

## Monitoring

> **Funkce Pro.** `backup:monitor`, zprávy o zdraví, tabulka `backup_history` i
> událost `BackupUnhealthy` jsou součástí [Backup Manager Pro](13-pro-a-ui.md).
> Free jádro zálohuje, obnovuje a uklízí i bez nich.

`php artisan backup:monitor` hlásí pro každý cíl:

- zda je **zdravý**;
- počet záloh a jejich celkovou velikost;
- stáří nejnovější zálohy.

Cíl je **nezdravý**, když nemá žádné zálohy, nebo je jeho nejnovější záloha starší
než:

```php
'monitoring' => ['max_backup_age_hours' => 25],
```

Příkaz končí nenulovým kódem, když je jakýkoli cíl nezdravý, takže slouží i jako
CI/uptime kontrola.

## Události

Balíček dispatchuje události, na které lze naslouchat:

| Událost | Kdy |
|---------|-----|
| `BackupStarted` | před začátkem běhu |
| `BackupCompleted` | po uložení zálohy na všechny cíle |
| `BackupFailed` | když běh vyhodí výjimku |
| `BackupUnhealthy` | když monitoring najde nezdravý cíl |

Každou událost životního cyklu také zapisuje do logu vestavěný listener
`LogsBackupActivity`, čímž máte auditní stopu ihned k dispozici.

## Notifikace

Selhání vás upozorní **e-mailem** (free jádro). **Slack** kanál a spouštěč
`unhealthy` jsou [Pro](13-pro-a-ui.md), protože staví na monitoringu:

```php
'monitoring' => [
    'notifications' => [
        'enabled' => true,
        'on' => ['failure', 'unhealthy'],   // success | failure | unhealthy
        'mail' => ['to' => ['ops@acme.test']],
        'slack' => ['webhook_url' => env('BACKUP_NOTIFY_SLACK')],
    ],
],
```

Doručení notifikací je best-effort: neúspěšná notifikace se zaloguje, ale nikdy
nepřeruší zálohu ani monitoring.
