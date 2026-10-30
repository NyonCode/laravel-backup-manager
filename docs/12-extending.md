---
title: Extending the package
description: Backup Manager is built around composition over inheritance.
key: extending
---
# Extending the package

Backup Manager is built around **composition over inheritance**. Every capability
lives behind a small interface with a focused implementation, wired together with
dependency injection. This makes it straightforward to replace or extend any part
— and to build a UI layer on top, which is an explicit design goal.

## The backup pipeline

A backup is a pipeline of stages threaded through a shared, mutable
`BackupContext`:

```text
PrepareWorkspace → DumpDatabases → CollectSourceFiles → BuildArchive
    → CompressArchive → EncryptArchive → BuildManifest → StoreBackup → CleanupWorkspace
```

Each stage implements the `BackupStage` contract:

```php
namespace Nyoncode\BackupManager\Contracts\Backup;

interface BackupStage
{
    public function handle(BackupContext $context, Closure $next): BackupContext;
}
```

The ordered list of stages is injected into `BackupManager`, so you can add,
remove or reorder stages by re-binding it in a service provider — no core changes
required. For example, to add a stage that uploads to an external audit service
after storing, insert your stage before `CleanupWorkspace`.

## Key contracts

| Contract | Responsibility | Default |
|----------|----------------|---------|
| `DatabaseDumper` / `DatabaseRestorer` | dump/restore one database | MySQL, PostgreSQL, SQLite |
| `Compressor` | compress/decompress the archive | gzip, bzip2, zstd, none |
| `Encryptor` | encrypt/decrypt the archive | OpenSSL AES-256, none |
| `Signer` | sign/verify the manifest | HMAC |
| `Archiver` | assemble/read the archive | streaming TAR (GNU extensions) |
| `BackupRepository` | list/read/delete stored backups | any Laravel disk |
| `RetentionStrategy` | decide which backups to keep | GFS, simple |
| `BackupStage` | one pipeline step | the stages above |

Swap any of them by binding your own implementation:

```php
use Nyoncode\BackupManager\Contracts\Compression\Compressor;

$this->app->bind(Compressor::class, MyCustomCompressor::class);
```

## Adding a database engine

1. Implement `DatabaseDumper` and `DatabaseRestorer` for your engine.
2. Add a case to `DatabaseManager` (or bind a decorated manager) mapping your
   `DatabaseDriver` to the new classes.

## Adding a compression algorithm

Implement `Compressor` and add a case to `CompressorManager::for()`.

## Listening to events

```php
use Nyoncode\BackupManager\Events\BackupCompleted;

Event::listen(BackupCompleted::class, function (BackupCompleted $event) {
    // $event->result->manifest, ->archiveFileName, ->destinations
});
```

## Building a UI on top

The package is CLI-first but UI-ready:

- Resolve `BackupManager`, `RestoreManager`, `RetentionManager`, `MonitorManager`,
  `VerifyManager` and `BackupRepository` from the container.
- They return typed value objects (`BackupResult`, `RestoreResult`, `HealthReport`,
  `StoredBackup`, `Manifest`) that map cleanly to a UI.
- No console assumptions leak into these services, so a private UI package can
  drive the exact same code paths as the CLI.
