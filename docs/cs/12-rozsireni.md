---
title: Rozšíření balíčku
description: Backup Manager je postaven na kompozici před dědičností.
key: extending
---
# Rozšíření balíčku

Backup Manager je postaven na **kompozici před dědičností**. Každá schopnost žije
za malým rozhraním s jednoúčelovou implementací, propojenou přes dependency
injection. Díky tomu lze snadno nahradit či rozšířit jakoukoli část — a stavět
nad ním UI vrstvu, což je explicitní cíl návrhu.

## Zálohovací pipeline

Záloha je pipeline fází procházejících sdíleným, mutovatelným `BackupContext`:

```text
PrepareWorkspace → DumpDatabases → CollectSourceFiles → BuildArchive
    → CompressArchive → EncryptArchive → BuildManifest → StoreBackup → CleanupWorkspace
```

Každá fáze implementuje rozhraní `BackupStage`:

```php
namespace Nyoncode\BackupManager\Contracts\Backup;

interface BackupStage
{
    public function handle(BackupContext $context, Closure $next): BackupContext;
}
```

Seřazený seznam fází je injektován do `BackupManager`, takže fáze můžete přidat,
odebrat nebo přeuspořádat opětovným navázáním v service provideru — bez zásahů do
jádra. Například fázi nahrávající do externí auditní služby vložíte před
`CleanupWorkspace`.

## Klíčová rozhraní

| Rozhraní | Odpovědnost | Výchozí |
|----------|-------------|---------|
| `DatabaseDumper` / `DatabaseRestorer` | dump/obnova jedné databáze | MySQL, PostgreSQL, SQLite |
| `Compressor` | komprese/dekomprese archivu | gzip, bzip2, zstd, none |
| `Encryptor` | šifrování/dešifrování archivu | OpenSSL AES-256, none |
| `Signer` | podpis/ověření manifestu | HMAC |
| `Archiver` | sestavení/čtení archivu | streaming TAR (GNU rozšíření) |
| `BackupRepository` | výpis/čtení/mazání záloh | jakýkoli Laravel disk |
| `RetentionStrategy` | rozhodnutí, co ponechat | GFS, simple |
| `BackupStage` | jeden krok pipeline | fáze výše |

Kteroukoli z nich zaměníte navázáním vlastní implementace:

```php
use Nyoncode\BackupManager\Contracts\Compression\Compressor;

$this->app->bind(Compressor::class, MujVlastniCompressor::class);
```

## Přidání databázového enginu

1. Implementujte `DatabaseDumper` a `DatabaseRestorer` pro svůj engine.
2. Přidejte case do `DatabaseManager` (nebo navažte dekorovaný manager), který
   mapuje váš `DatabaseDriver` na nové třídy.

## Přidání kompresního algoritmu

Implementujte `Compressor` a přidejte case do `CompressorManager::for()`.

## Naslouchání událostem

```php
use Nyoncode\BackupManager\Events\BackupCompleted;

Event::listen(BackupCompleted::class, function (BackupCompleted $event) {
    // $event->result->manifest, ->archiveFileName, ->destinations
});
```

## Stavba UI nad balíčkem

Balíček je primárně CLI, ale připravený na UI:

- Z kontejneru resolvněte `BackupManager`, `RestoreManager`, `RetentionManager`,
  `MonitorManager`, `VerifyManager` a `BackupRepository`.
- Vracejí typované value objekty (`BackupResult`, `RestoreResult`, `HealthReport`,
  `StoredBackup`, `Manifest`), které se čistě mapují do UI.
- Do těchto služeb neproniká žádný předpoklad o konzoli, takže privátní UI balíček
  může řídit přesně stejné cesty kódu jako CLI.
