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

### Plány podle profilů

Profilu dáte vlastní rytmus přes `schedule`:

```php
'profiles' => [
    'default' => ['type' => 'full'],                                  // použije schedule.backup
    'reports' => ['type' => 'database', 'schedule' => '0 3 * * 0'],   // týdně
    'archive' => ['type' => 'files'],                                 // neplánuje se
],
```

- **Výchozí profil** bez vlastního `schedule` použije sdílené `schedule.backup`.
- **Jakýkoli jiný profil** bez `schedule` se **neplánuje** — profil určený pro
  ruční spouštění nezačne zálohovat jen proto, že jste zapnuli scheduler.

Scheduler registruje jeden příkaz na každý naplánovaný profil:

```text
php artisan backup:run                      # výchozí profil, jako dřív
php artisan backup:run --profile=reports
php artisan backup:clean --profile=reports  # podle sdíleného schedule.cleanup
```

Retence (`schedule.cleanup`) a s Pro i `backup:monitor` (`schedule.monitor`) běží
na sdíleném rytmu pro výchozí profil a pro každý naplánovaný profil.

Validátor konfigurace (`ConfigValidator`, používá ho i MCP server a report
konfigurace v dashboardu) hlásí **informaci** u jiného než výchozího profilu bez
plánu a **chybu** u `schedule`, který není platný pětipolový cron výraz.

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

Když je zapnuto, `backup:run`, `backup:clean` i `backup:monitor` odešlou job do
fronty místo běhu na místě (musí běžet worker na dané frontě). Synchronní běh
vynutíte přes `--sync`:

```bash
php artisan backup:run            # ve frontě, když queue.enabled = true
php artisan backup:run --sync     # vždy okamžitě
php artisan backup:clean          # CleanBackupsJob
php artisan backup:monitor        # MonitorBackupsJob (Pro)
```

Retence patří do fronty stejně jako záloha: u inkrementálního profilu nejdřív
spočítá reference nad celým blob poolem a teprve pak uklízí, což u velké
instalace trvá minuty. Naplánovaná bez fronty se tohle děje v procesu scheduleru
na webovém stroji.

`backup:clean --dry-run` do fronty nikdy nejde — jeho jediným výstupem je náhled,
který vypíše. Zařazený `backup:monitor` při nezdravém cíli **selže jako job**, což
je ve frontě obdoba nenulového návratového kódu příkazu, takže se objeví ve
`failed_jobs` a ne jako v pořádku vyřízený běh.

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
než práh zastaralosti profilu:

```php
'monitoring' => ['max_backup_age_hours' => 25],   // sdílená spodní hranice
```

Příkaz končí nenulovým kódem, když je jakýkoli cíl nezdravý, takže slouží i jako
CI/uptime kontrola.

### Práh podle rytmu záloh

Pevných 25 hodin by týdenní profil hlásilo jako zastaralý každý den. Práh proto
sleduje plán profilu:

1. Je-li nastaveno `profiles.*.monitoring.max_backup_age_hours`, platí ono.
2. Jinak je to větší z hodnot: sdílené `monitoring.max_backup_age_hours` a
   **nejdelší mezera mezi dvěma běhy cronu plus rezerva**.

Rezerva je 10 % intervalu, nejméně 1 hodina a nejvýše 24 hodin. Sdílená hodnota
je spodní hranice — nikdy se nesnižuje. Počítá se *nejdelší* mezera: `0 2 1,15 * *`
má mezi 15. a 1. dalšího 31denního měsíce mezeru 17 dní a práh ji pokrývá.

Příklad — profil zálohovaný zhruba jednou za dva týdny:

```php
'profiles' => [
    'archive' => [
        'type' => 'full',
        'schedule' => '0 2 */14 * *',   // běží 1., 15. a 29.
    ],
],
```

> Cron neumí vyjádřit skutečný 14denní interval. `*/14` v poli dne v měsíci
> znamená dny 1, 15 a 29 každého měsíce a pak začíná znovu — běhy jsou tedy po
> 14 dnech, na konci měsíce s kratší mezerou.

Nejdelší mezera je 14 dní (336 hodin); rezerva je 10 % z toho, tedy 33,6 hodiny,
zastropovaná na 24. Profil je v pořádku, dokud je jeho nejnovější záloha mladší
než zhruba 14 dní plus 24 hodin (360 hodin), potom je označen. Sdílených 25 hodin
je jen spodní hranice, takže se neuplatní. Hodnotu si můžete zafixovat:

```php
'archive' => [
    'schedule' => '0 2 */14 * *',
    'monitoring' => ['max_backup_age_hours' => 400],
],
```

Profil bez plánu použije jen sdílenou hodnotu. Monitoring běží pro výchozí profil
a každý naplánovaný profil (`backup:monitor --profile=X`).

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
