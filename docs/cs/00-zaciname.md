---
title: Začínáme
description: "Tento tutoriál vás vezme od nuly k reálné, ověřené záloze, kterou umíte obnovit — přibližně za deset minut."
key: getting-started
---
# Začínáme

Tento tutoriál vás vezme od nuly k **reálné, ověřené záloze, kterou umíte
obnovit** — přibližně za deset minut. Postupujte odshora dolů; každý krok je
příkaz, který spustíte, a výstup, který byste měli vidět.

Na konci budete mít vytvořenou zálohu, prohlédnete si ji, ověříte její integritu,
obnovíte ji a budete vědět, jak ji připravit pro produkci.

## Co vás čeká

1. Instalace balíčku do Laravel aplikace.
2. Vytvoření první zálohy.
3. Prohlídka toho, co se uložilo.
4. Ověření, že se záloze dá věřit.
5. Bezpečná obnova.
6. Příprava pro produkci: šifrování, ukládání mimo server a spouštění podle plánu.

## Než začnete

Potřebujete existující aplikaci na **Laravelu 12 nebo 13** a **PHP 8.3+**. Pokud
zálohujete databázi, musí být nainstalované její příkazové nástroje (například
`mysqldump` pro MySQL). Kompletní seznam je na stránce
[Instalace](01-instalace.md) — ale pro první spuštění proti SQLite nebo jen
souborům nepotřebujete nic navíc.

## 1. Nainstalujte balíček

V kořeni aplikace nainstalujte balíček a publikujte jeho konfiguraci:

```bash
composer require nyoncode/laravel-backup-manager
php artisan vendor:publish --tag="backup-manager-config"
```

A je to — Laravel balíček zaregistruje sám a vy teď máte soubor
`config/backup-manager.php`. Výchozí nastavení zálohuje celou aplikaci (databázi
i soubory) na lokální úložiště, což je pro tento tutoriál ideální.

## 2. Vytvořte první zálohu

Spusťte výchozí zálohu:

```bash
php artisan backup:run
```

Uvidíte přehled toho, co se zachytilo:

```text
   INFO  Running backup for profile [default]….

  Archive ............ default-2026-07-19-020000-a1b2c3.tar.gz
  Mode ............... Archive
  Size ............... 4.72 MB
  Databases .......... 1
  Files .............. 128
  Destinations ....... local

   INFO  Backup completed successfully.
```

Právě jste vytvořili svou první zálohu. Leží na disku `local` ve složce
`backups/`.

## 3. Prohlédněte si, co se uložilo

Vypište zálohy, o kterých balíček ví:

```bash
php artisan backup:list
```

Každá záloha jsou **dva soubory**: samotný archiv (komprimovaný `.tar`, který lze
volitelně zašifrovat) a vedle něj `*.manifest.json`. Manifest je prostý,
nešifrovaný záznam o tom, co je uvnitř — prostředí, kontrolní součet archivu a
hash každého souboru — takže zálohu lze zkontrolovat a věřit jí samostatně, i bez
tohoto balíčku.

## 4. Ověřte, že se jí dá věřit

Záloha má cenu jen tehdy, když není poškozená. Ověřte tu nejnovější vůči jejímu
manifestu:

```bash
php artisan backup:verify
```

```text
   INFO  Verifying backup [default-2026-07-19-020000-a1b2c3]….

  Archive checksum ... OK
  Files ............. 128/128 verified

   INFO  Backup is valid.
```

## 5. Obnovte ji

Obnova vrátí obsah zálohy zpět. Vyzkoušejte to:

```bash
php artisan backup:restore
```

Příkaz si vyžádá potvrzení, pak archiv **nejdřív ověří** a teprve poté na cokoli
sáhne. Ve výchozím nastavení navíc vytvoří snapshot stavu před obnovou a **při
selhání obnovy vše automaticky vrátí zpět** — takže špatná obnova vás nikdy
nenechá v horším stavu než předtím. Obnovit můžete jen databázi nebo jen soubory;
volby najdete v [Obnově](07-obnova.md).

> **Obnovu si nejdřív vyzkoušejte na stagingu.** Obnova přepíše současná data daty
> ze zálohy — to je její smysl, takže ji spouštějte tam, kde to opravdu chcete.

## 6. Připravte ji pro produkci

Lokální nešifrovaná záloha je skvělý začátek. Tři změny z ní udělají něco, čemu
byste věřili v produkci:

- **Zašifrujte ji.** Nastavte algoritmus a klíč, aby byl archiv na disku
  nečitelný. Viz [Zabezpečení a manifest](06-zabezpeceni.md).
- **Ukládejte ji mimo server.** Záloha na stejném serveru jako aplikace zmizí
  spolu se serverem. Přidejte do `destinations` profilu disk S3 (nebo FTP/SFTP/…)
  a zapisujte na obě místa naráz. Viz [Úložiště](05-uloziste.md).
- **Spouštějte ji automaticky.** Zapněte vestavěné plánování, aby zálohy, úklid a
  kontroly zdraví probíhaly bez vás. Viz [Plánování a monitoring](10-planovani.md).

Například v `config/backup-manager.php`:

```php
'profiles' => [
    'default' => [
        // …
        'destinations' => ['s3', 'local'],   // mimo server + lokální kopie
    ],
],
'encryption' => [
    'algorithm' => 'aes-256-gcm',
    'key' => env('BACKUP_ENCRYPTION_KEY'),   // 32 bajtů, base64: akceptováno
],
'schedule' => ['enabled' => true],
```

## Kam dál

- [Konfigurace](02-konfigurace.md) — všechny volby, vysvětlené sekci po sekci.
- [Databáze](03-databaze.md) a [Soubory](04-soubory.md) — vyberte přesně, co zachytit.
- [Retence](08-retence.md) — držte historii pod kontrolou, ať zálohy nezaplní disk.
- [Pro a UI dashboard](13-pro-a-ui.md) — inkrementální zálohy, monitoring a živý webový dashboard.
