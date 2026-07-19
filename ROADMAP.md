# Roadmap

This document sketches where Backup Manager could go after 1.0. It is a
direction, not a promise — items may be reordered, merged or dropped.

The package was deliberately built around small interfaces and a stage pipeline
(`DatabaseDumper`, `Compressor`, `Encryptor`, `Signer`, `Archiver`,
`BackupRepository`, `RetentionStrategy`, `BackupStage`). Almost everything below
is therefore **additive** — a new implementation of an existing contract or a new
pipeline stage — rather than a rewrite of the core.

---

## Guiding principles

- Stay composable: new capabilities arrive as new implementations, not new
  branches inside the core.
- Keep the CLI first-class and the managers UI-ready (typed value objects).
- Never trade correctness or safety for features. A backup that can't be restored
  is worse than no backup.

---

### 1. Incremental & differential backups with deduplication

Every backup is currently a full backup. The manifest already records a checksum
for **every file**, which is exactly the index needed to diff against the previous
backup and store only what changed.

- Diff the current tree against the latest manifest (added / changed / removed).
- Content-addressed blob store (a file is stored once, keyed by its hash) →
  deduplication across backups.
- New `BackupStage` + a new `Archiver`/store implementation; the restore path
  reassembles a full snapshot from base + increments.

**Impact:** large storage and time savings. **Foundation:** already present in the
manifest.

> **Not a replacement for full backups.** Incremental restores reassemble a
> snapshot from a base plus a *chain* of increments — a single missing link
> breaks the restore. So incrementals target the **frequent periodic cadence**
> (hourly/daily) where storage matters, while full, self-contained backups remain
> the reliable anchors (GFS keeps fulls for exactly this reason).
>
> In particular, a **pre-deploy rollback** wants one self-contained restore point,
> not a chain — a small **database-only full backup** is the right tool there
> (the code is rolled back by your deploy tooling / git, not by a backup).
> True *incremental databases* are a different feature — point-in-time recovery
> via binlog/WAL — not file-style diffing.

### 2. Restorability testing (`backup:test`)

`backup:verify` proves the archive matches its manifest. It does **not** prove the
backup actually restores. v2 adds a real, ephemeral restore:

- Restore into a throwaway sandbox (temporary database + temp directory).
- Run integrity assertions (row counts, checksums), then tear everything down.
- Schedulable, so "our backups are restorable" becomes a monitored fact.

### 3. Fully streamed pipeline (no local staging)

Today the archive is assembled in a working directory, then compressed, encrypted
and uploaded. The archiver and cipher already stream; v2 connects the whole
pipeline as a single pass (tar → compress → encrypt → upload via PSR streams /
pipes), so a host never needs free disk equal to the backup size.

---

## Themed backlog

### Databases

- **Point-in-time recovery**: MySQL binlog / PostgreSQL WAL archiving.
- **Version-portable dumps**: detect the server version and choose compatible
  flags automatically (resolves the `pg_dump` newer-than-server issue).
- **Parallel dumps** across multiple connections.

### Encryption

- **Streamed AES-256-GCM** (chunked AEAD with per-chunk tags) to remove the
  in-memory GCM size limit.
- **Envelope / asymmetric encryption** (KMS, `age`/PGP): encrypt with a public key,
  decrypt only with an offline private key. Key rotation.

### Storage & compliance

- Native S3 **multipart upload + SSE-KMS**, cross-region awareness.
- **Immutable / WORM** backups (S3 Object Lock), legal hold — ransomware
  resilience.
- Selective deletion of records **inside** a backup (GDPR erasure).

### Performance & concurrency

- Concurrent uploads to multiple destinations.
- Upload backpressure / rate limiting.

### Observability & UI

- **Per-stage progress events** (0–100%) for a live CLI/UI progress bar.
- A read model / API over `backup_history`: search, diff two backups, size trends.
- Prometheus / OpenTelemetry metrics; Laravel Horizon integration.
- More notification channels via native **Laravel Notifications** (Discord, Teams,
  generic webhook).
- The planned **private UI package** — the managers already return typed value
  objects, so the UI only consumes them.

### Developer experience

- `backup:run --dry-run` (report what would be backed up without doing it).
- Fluent, first-class profile builders as an alternative to config arrays.
- An `backup:install` scaffolding command.

---

## Non-goals (intentionally out of scope)

- Re-implementing cloud provider SDKs — Laravel's Flysystem disks are enough.
- A GUI file browser inside archives — that belongs to the UI package, not the
  core.
- Exotic database engines until there is real demand.

---

## How to contribute an idea

Open an issue describing the use case (not just the feature). Because the core is
interface-driven, a proof of concept is usually a single new class implementing an
existing contract — a great way to validate an idea before it lands here.
