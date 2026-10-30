---
title: AI asistenti (MCP)
description: "Nastavení záloh je hromádka rozhodnutí — co zahrnout, kam ukládat, jak šifrovat, jak dlouho držet — a každé z nich se dá nenápadně pokazit."
key: mcp
---
# AI asistenti (MCP)

Nastavení záloh je hromádka rozhodnutí — co zahrnout, kam ukládat, jak šifrovat,
jak dlouho držet — a každé z nich se dá nenápadně pokazit. Tento balíček umí ta
rozhodnutí i podklady pro ně předat AI asistentovi:

```bash
php artisan backup:mcp
```

Spustí se server [Model Context Protocolu](https://modelcontextprotocol.io) na
stdin/stdout. Připojený asistent si přečte instalaci, navrhne konfiguraci, zapíše
ji, spustí zálohu a ověří výsledek — přes úplně stejný kód jako CLI. Nic dalšího
instalovat netřeba: server je součástí free jádra.

> **Asistent nikdy nic neobnovuje.** Obnova přepisuje běžící aplikaci, takže
> zůstává vědomým lidským krokem: `php artisan backup:restore`.

## Připojení klienta

**Claude Code**

```bash
claude mcp add backup-manager -- php artisan backup:mcp
```

**Claude Desktop, Cursor a další klienti s JSON konfigurací**

```json
{
  "mcpServers": {
    "backup-manager": {
      "command": "php",
      "args": ["artisan", "backup:mcp"],
      "cwd": "/cesta/k/aplikaci"
    }
  }
}
```

Na stdout server posílá výhradně protokolové rámce — vlastní stavové hlášky jdou
na stderr, kde je klienti zobrazí jako log serveru. Pokud vaše aplikace loguje na
stdout (`LOG_CHANNEL=stdout`), přesměrujte ji pro tento příkaz na `stderr`, jinak
klient uvidí log jako poškozený protokol.

## Co může asistent zavolat

| Nástroj | Úroveň | Co dělá |
|---------|--------|---------|
| `backup_setup_guide` | read | Postup práce a rozhodnutí, na kterých záleží |
| `backup_overview` | read | Edice, profily, šifrování, plánování, počty problémů |
| `backup_inspect_environment` | read | Disky, databázová spojení, binárky, volné místo |
| `backup_list_profiles` | read | Každý profil tak, jak ho vyhodnotí pipeline |
| `backup_config_reference` | read | Všechny konfigurační klíče, typy, povolené hodnoty |
| `backup_validate_configuration` | read | Vše, co je špatně, a jak to spravit |
| `backup_list_backups` | read | Co je skutečně uloženo, od nejnovějšího |
| `backup_preview_retention` | read | Co by retence smazala |
| `backup_configure` | configure | Zapisuje `.env` / `config/backup-manager.php` |
| `backup_run` | operate | Vytvoří zálohu |
| `backup_verify` | operate | Ověří uloženou zálohu proti manifestu |
| `backup_apply_retention` | destructive | Smaže zálohy mimo retenční politiku |

Server navíc nabízí průvodce nastavením, referenci konfigurace a aktuální
konfiguraci (bez přihlašovacích údajů) jako MCP **resources** a prompt
`set-up-backups`.

## Oprávnění

```php
'mcp' => [
    'enabled' => (bool) env('BACKUP_MCP', true),
    'allow_writes' => (bool) env('BACKUP_MCP_ALLOW_WRITES', true),
    'allow_destructive' => (bool) env('BACKUP_MCP_ALLOW_DESTRUCTIVE', false),
    'environments' => ['local'],
],
```

Každý nástroj deklaruje úroveň přístupu a server zveřejní jen ty úrovně, které
instalace povoluje — nepovolený nástroj se v seznamu neobjeví a při pokusu o
zavolání vrátí důvod a přepínač, kterým se povoluje, místo tichého selhání.

- **read** a **operate** jsou dostupné vždy.
- **configure** vyžaduje `allow_writes` (ve výchozím stavu zapnuto).
- **destructive** vyžaduje `allow_destructive` (**vypnuto**). Na této úrovni je
  jen `backup_apply_retention` a i tak běží nasucho, dokud mu neřeknete jinak.
- `environments` určuje, kde se server vůbec spustí; mimo tento seznam
  `backup:mcp` odmítne naběhnout. `['*']` povolí všechna prostředí.

Přepínače zúží jedno sezení, aniž by sáhly na konfiguraci:

```bash
php artisan backup:mcp --read-only          # nic se nesmí zapisovat
php artisan backup:mcp --allow-destructive  # povolí mazání retencí
```

## Jak se zapisuje změna konfigurace

`backup_configure` bere tečkové cesty z reference a proměnné prostředí tohoto
balíčku:

```json
{
  "config": { "profiles.default.destinations": ["s3"], "retention.simple.keep_latest": 14 },
  "env": { "BACKUP_ENCRYPTION": "aes-256-cbc", "BACKUP_SCHEDULE": true },
  "dry_run": true
}
```

Co se stane mezi tímto voláním a změněným souborem:

1. **Každý klíč se ověří proti referenci.** Nezdokumentovaný klíč nebo hodnota
   špatného typu či mimo povolenou množinu zruší celé volání — nic se nezapíše a
   chyba pojmenuje nejbližší skutečný klíč.
2. **Výsledek se zvaliduje dřív, než vznikne.** Změna se aplikuje na kopii
   konfigurace v paměti a projde stejnými kontrolami jako
   `backup_validate_configuration`. Suchý běh tyto nálezy vrátí, takže chybu
   uvidíte před zápisem. Zápis změny, po které by zůstaly chyby, je odmítnut,
   dokud volající nepošle `force`.
3. **Každý klíč jde do správného souboru.** Nastavení, které konfigurační soubor
   čte přes `env()`, se zapíše do `.env`, ne natvrdo do PHP — jinak by se
   nastavení tiše odpojilo od prostředí, se kterým se nasazuje. Tajné hodnoty se
   zapisují, ale nikdy neputují zpátky do odpovědi.
4. **Konfigurační soubor se edituje, ne generuje.** Zdroj se tokenizuje a nahradí
   se jen cílová hodnota, takže komentáře i formátování zůstanou. Neexistující
   klíč se vloží včetně chybějících úrovní (tak vznikne i celý nový profil).
5. **Výsledek se před zápisem znovu naparsuje**, původní soubor se zkopíruje do
   `storage/app/backup-manager/config-backups/` a nový se zapíše atomicky. Když
   cokoli z toho selže, nezmění se nic.

Nakonec se hodnoty promítnou i do běžícího procesu, takže `backup_run` ve stejném
sezení použije to, co bylo právě nastaveno. Ostatní procesy změnu uvidí po svém
dalším startu — nebo po `php artisan config:clear`, pokud aplikace konfiguraci
cachuje; na to nástroj upozorní.

## Jak to používat dobře

Řekněte si o výsledek běžnou řečí — „nastav noční šifrované zálohy na S3, drž
dva týdny" — a čekejte, že asistent:

1. si přečte průvodce, prostředí a referenci, než cokoli navrhne,
2. ukáže suchý běh,
3. změnu aplikuje a pak **spustí a ověří skutečnou zálohu**.

Dvě věci zůstávají na vás a dobrý asistent to řekne:

- **Šifrovací klíč.** Uložte si kopii jinam než na server. Bez něj nelze žádnou
  zálohu obnovit.
- **Plánovač.** `schedule.enabled` zaregistruje příkazy do Laravel scheduleru,
  který běží jen tehdy, když systémový cron volá každou minutu
  `php artisan schedule:run`.

A jednu věc za vás neudělá nikdo: nacvičit obnovu. Záloha, která nikdy nebyla
obnovena, je naděje, ne záloha.

## Vlastní nástroj

Nástroje se sbírají přes container tag, takže aplikace nebo doplněk může přidat
vlastní, aniž by sahal na server:

```php
use Nyoncode\BackupManager\BackupManagerServiceProvider;

$this->app->tag([MyTool::class], BackupManagerServiceProvider::MCP_TOOLS);
```

`MyTool` implementuje `Nyoncode\BackupManager\Contracts\Mcp\McpTool` (potomek
`Nyoncode\BackupManager\Mcp\Tools\Tool` dostane pomocníky pro schéma) a deklaruje
úroveň `McpToolAccess`, na kterou se pak vztahují stejná pravidla oprávnění.
