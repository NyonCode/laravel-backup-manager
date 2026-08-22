# Přehled CLI

Všechny příkazy přijímají `--profile=` pro cílení na konkrétní profil (výchozí:
nakonfigurovaný profil `default`).

## `backup:run`

Vytvoří novou zálohu.

```bash
php artisan backup:run
php artisan backup:run --profile=nightly
```

## `backup:list`

Vypíše uložené zálohy pro cíl profilu.

```bash
php artisan backup:list
php artisan backup:list --profile=nightly --disk=s3
```

| Volba | Popis |
|-------|-------|
| `--disk=` | Přepíše kontrolovaný cílový disk |

## `backup:restore`

Obnoví zálohu. Ptá se na potvrzení, pokud není `--force`.

```bash
php artisan backup:restore
php artisan backup:restore moje-zaloha.tar.gz.enc --force
```

| Volba | Popis |
|-------|-------|
| `--disk=` | Přepíše cílový disk |
| `--database` | Obnovit pouze databáze |
| `--files` | Obnovit pouze soubory |
| `--only=*` | Obnovit jen soubory pod těmito absolutními prefixy cest |
| `--no-verify` | Přeskočit ověření integrity archivu |
| `--force` | Neptat se na potvrzení |

## `backup:verify`

Ověří integritu zálohy vůči manifestu (kontrolní součet + podpis) bez obnovy.
Při selhání končí nenulovým kódem.

```bash
php artisan backup:verify
php artisan backup:verify moje-zaloha.tar.gz.enc
```

## `backup:clean`

Aplikuje retenční politiku a smaže zálohy mimo ni.

```bash
php artisan backup:clean
php artisan backup:clean --dry-run
php artisan backup:clean --profile=nightly --disk=s3
```

| Volba | Popis |
|-------|-------|
| `--disk=` | Vyčistit jen tento cílový disk |
| `--dry-run` | Zobrazit, co by se smazalo, bez mazání |

## `backup:monitor`

Nahlásí zdraví záloh profilu. Končí nenulovým kódem, je-li jakýkoli cíl nezdravý.

```bash
php artisan backup:monitor
php artisan backup:monitor --profile=nightly
```

## `backup:mcp`

Spustí server Model Context Protocolu na stdin/stdout, takže AI asistent může
zálohy prozkoumat, nastavit, spustit a ověřit. Viz
[AI asistenti (MCP)](14-mcp.md).

```bash
php artisan backup:mcp
php artisan backup:mcp --read-only
```

| Volba | Popis |
|-------|-------|
| `--allow-writes` | Povolit zápis konfigurace pro toto sezení |
| `--deny-writes` | Zakázat zápis konfigurace pro toto sezení |
| `--allow-destructive` | Povolit mazání záloh pro toto sezení |
| `--read-only` | Nabídnout jen nástroje, které nic nemění |

## Návratové kódy

| Kód | Význam |
|-----|--------|
| `0` | úspěch / zdravé |
| `1` | selhání / nezdravé / ověření selhalo |
