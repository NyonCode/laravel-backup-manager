---
title: "Incremental & deduplicated backups"
description: "Every ordinary backup is a full, self-contained archive."
key: incremental
---
# Incremental & deduplicated backups

> **Pro feature.** Incremental/deduplicated backups require
> [Backup Manager Pro](13-pro-and-ui.md). The free core can list snapshots but
> refuses to create, restore or verify them.

Every ordinary backup is a full, self-contained archive. **Incremental** backups
instead store a *snapshot* whose file contents live in a shared,
content-addressed **blob pool** — so only content that changed since the previous
snapshot is uploaded, and content that repeats across backups is stored once.

This is ideal for a **frequent cadence** (hourly/daily) where storage and time
matter. Full archives remain the reliable, standalone anchors; incrementals
complement them, they don't replace them.

> **Mode is independent of scope.** A profile's `type` (`full` / `database` /
> `files`) says *what* to back up; its `mode` (`archive` / `incremental`) says
> *how* to store it. Any scope can use either mode.

## Enabling it

Set `mode` on a profile:

```php
'profiles' => [
    'hourly' => [
        'name' => env('APP_NAME'),
        'type' => 'files',
        'mode' => 'incremental',      // ← store as a deduplicated snapshot
        'files' => ['include' => [base_path()]],
        'destinations' => ['s3'],
        'path' => 'backups',
    ],
],
```

Then run it like any other profile:

```bash
php artisan backup:run --profile=hourly
```

The command reports how much was deduplicated:

```text
Snapshot       app-2026-07-19-020000-1a2b3c.manifest.json
Mode           Incremental snapshot
Files          12,481
Deduplicated   37 new, 12,444 reused (18.2 MB new content)
Destinations   s3
```

## How it works

```text
{path}/
  snapshots/
    {name}.manifest.json     one index per snapshot (lists every file + its hash)
  blobs/
    {algorithm}/{ab}/{digest} the content-addressed pool (one blob per unique content)
```

- Every file is stored as a **blob keyed by the hash of its content**. Identical
  content — across files, snapshots, or runs — is stored exactly once.
- A snapshot's **manifest is its index**: it lists every file with the hash that
  locates its blob. Because addressing is by content, **each snapshot is
  self-sufficient** given the pool — there is no fragile chain of increments to
  walk on restore.
- Archive backups and snapshots **coexist** under the same `path` without
  collision.

## Restore

Restore is identical to archive backups — the snapshot is reassembled from the
pool transparently:

```bash
php artisan backup:restore --profile=hourly           # newest snapshot
php artisan backup:restore app-2026-07-19-...manifest.json --profile=hourly
```

Restoring an **older** snapshot faithfully returns its historical content, even
after newer snapshots exist, because each blob is addressed by content. All
restore options apply: `--database`, `--files`, `--only=`, `--unsafe`,
`--no-verify`. Verified restores check the snapshot's file-index anchor and every
blob's checksum. See [Restore](07-restore.md).

## Compression & encryption

Blobs honour the profile's `compression` and `encryption` settings: each blob is
compressed and then encrypted, but still **keyed by its plaintext hash**, so
deduplication is fully preserved and the bytes at rest are protected.

> **Treat the algorithm and key as fixed for a pool's lifetime**, like a
> repository password. Changing them leaves earlier blobs unreadable by later
> snapshots that expect the new setting.

## Retention & garbage collection

`backup:clean` prunes snapshots by the same policy as archives (GFS / simple +
age / size caps), then reclaims the pool by **reference-counted mark-and-sweep**:

```bash
php artisan backup:clean --profile=hourly --dry-run
```

```text
s3             would delete 3 backup(s)
  app-2026-07-12-...  2026-07-12 02:00
  app-2026-07-13-...  2026-07-13 02:00
  app-2026-07-14-...  2026-07-14 02:00
  blob pool    would reclaim 214 blob(s) · 1.9 GB
```

A blob is deleted **only when no surviving snapshot references it** — pruning one
snapshot never breaks another that shares content. The sweep is **fail-closed**:
if any surviving manifest cannot be read, no blob is deleted. See
[Retention](08-retention.md).

> Run `backup:clean` when backups are **not** running. Like all mark-and-sweep
> collectors, it assumes a stable set of snapshots for the duration of the sweep.

## Verify & monitor

- `backup:verify` confirms a snapshot's file index, that every referenced blob is
  present, and that each blob (decrypted/decompressed) hashes to its recorded
  value — a real restorability check.
- `backup:list` and `backup:monitor` count snapshots alongside archives, so a
  snapshot-only profile is never reported as empty. See [CLI reference](11-cli.md).

## When to use which

| Use case | Recommended |
| --- | --- |
| Nightly full, kept for months | **archive** (self-contained anchor) |
| Hourly/daily, storage-sensitive | **incremental** |
| Pre-deploy rollback point | small **archive**, database-only |
| Many near-identical hosts/backups | **incremental** (cross-backup dedup) |
