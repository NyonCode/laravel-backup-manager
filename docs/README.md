# Backup Manager — Documentation

Welcome to the Backup Manager documentation. New here? Follow the
[Getting started](00-getting-started.md) tutorial — it takes you from nothing to
a verified, restorable backup in about ten minutes. After that, the pages below
are your reference.

## Contents

1. [Getting started](00-getting-started.md) — a from-scratch tutorial
2. [Installation](01-installation.md)
3. [Configuration](02-configuration.md)
4. [Databases](03-databases.md)
5. [Files](04-files.md)
6. [Storage destinations](05-storage.md)
7. [Security & the manifest](06-security.md)
8. [Restore](07-restore.md)
9. [Retention](08-retention.md)
10. [Incremental & deduplicated backups](09-incremental.md)
11. [Scheduling & monitoring](10-scheduling.md)
12. [CLI reference](11-cli.md)
13. [Extending the package](12-extending.md)
14. [Pro & the UI dashboard](13-pro-and-ui.md)

> **Open-core.** This package is the free MIT core. Incremental backups, GFS
> retention, HMAC signing, monitoring and the web dashboard are commercial
> add-ons — see [Pro & the UI dashboard](13-pro-and-ui.md). Pro-only sections in
> these docs are marked with a **Pro** callout.

## Concepts in one minute

- A **profile** is a named backup definition (what to back up, where to store it).
  You can have many; the `default` profile is used when none is given.
- A **backup** is a single compressed, optionally encrypted TAR archive plus a
  JSON **manifest** sidecar.
- A **destination** is a Laravel filesystem disk. A profile can write to several
  at once.
- **Retention** decides which historical backups to keep; **restore** brings a
  backup back, always after verifying its integrity against the manifest.

## Design philosophy

The package favours **composition over inheritance**. Every capability lives
behind a small interface (`DatabaseDumper`, `Compressor`, `Encryptor`, `Signer`,
`Archiver`, `BackupRepository`, `RetentionStrategy`, `BackupStage`) with a focused
implementation, wired together with dependency injection. This makes each part
independently testable and easy to replace — and makes building a UI layer on top
straightforward, which is an explicit goal of this package.
