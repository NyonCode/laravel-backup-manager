---
title: Backup Manager — Dokumentace
description: Obsah dokumentace balíčku Backup Manager — od úvodního návodu po obnovu, retenci a plánování záloh.
key: readme
---
# Backup Manager — Dokumentace

Vítejte v dokumentaci balíčku Backup Manager. Jste tu poprvé? Projděte si
tutoriál [Začínáme](00-zaciname.md) — vezme vás od nuly k ověřené a obnovitelné
záloze přibližně za deset minut. Poté vám stránky níže poslouží jako reference.

## Obsah

1. [Začínáme](00-zaciname.md) — tutoriál od nuly
2. [Instalace](01-instalace.md)
3. [Konfigurace](02-konfigurace.md)
4. [Databáze](03-databaze.md)
5. [Soubory](04-soubory.md)
6. [Úložiště](05-uloziste.md)
7. [Zabezpečení a manifest](06-zabezpeceni.md)
8. [Obnova](07-obnova.md)
9. [Retence](08-retence.md)
10. [Inkrementální a deduplikované zálohy](09-inkrementalni.md)
11. [Plánování a monitoring](10-planovani.md)
12. [Přehled CLI](11-cli.md)
13. [Rozšíření balíčku](12-rozsireni.md)
14. [Pro a UI dashboard](13-pro-a-ui.md)
15. [AI asistenti (MCP)](14-mcp.md)

> **Open-core.** Tento balíček je free MIT jádro. Inkrementální zálohy, GFS
> retence, HMAC podpis, monitoring a webový dashboard jsou komerční doplňky —
> viz [Pro a UI dashboard](13-pro-a-ui.md). Sekce jen pro Pro jsou v této
> dokumentaci označené calloutem **Pro**.

## Koncepty v jedné minutě

- **Profil** je pojmenovaná definice zálohy (co zálohovat a kam ukládat).
  Můžete jich mít víc; profil `default` se použije, když žádný neurčíte.
- **Záloha** je jeden komprimovaný, volitelně šifrovaný TAR archiv plus
  přiložený **manifest** (JSON sidecar).
- **Cíl (destination)** je Laravel filesystem disk. Profil může zapisovat na
  více cílů najednou.
- **Retence** rozhoduje, které historické zálohy ponechat; **obnova** vrátí
  zálohu zpět, vždy až po ověření integrity vůči manifestu.

## Filozofie návrhu

Balíček upřednostňuje **kompozici před dědičností**. Každá schopnost je za malým
rozhraním (`DatabaseDumper`, `Compressor`, `Encryptor`, `Signer`, `Archiver`,
`BackupRepository`, `RetentionStrategy`, `BackupStage`) s jednoúčelovou
implementací, propojenou přes dependency injection. Každá část je tak nezávisle
testovatelná a snadno zaměnitelná — a stavba UI vrstvy nad balíčkem je přímočará,
což je explicitní cíl tohoto balíčku.

> 🇬🇧 English documentation: [`docs/README.md`](../README.md).
