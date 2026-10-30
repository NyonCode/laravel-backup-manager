---
title: "Security & the manifest"
description: "Backup Manager provides four layers of protection: encryption, checksums,"
key: security
---
# Security & the manifest

Backup Manager provides four layers of protection: **encryption**, **checksums**,
**signing** and the **manifest**.

## Encryption

The final archive can be encrypted with AES-256 using `ext-openssl`:

```php
'encryption' => [
    'algorithm' => 'aes-256-gcm',        // none | aes-256-cbc | aes-256-gcm
    'key' => env('BACKUP_ENCRYPTION_KEY'),
],
```

### Choosing a mode

| Mode | Authenticated | Streaming | Use when |
|------|---------------|-----------|----------|
| `aes-256-cbc` | no | yes (flat memory) | very large archives |
| `aes-256-gcm` | **yes** (tamper-evident) | buffered in memory | archives that fit comfortably in memory |

GCM detects tampering on decrypt (an altered archive fails to restore). CBC
streams block-by-block so arbitrarily large archives encrypt with flat memory.

> **Memory:** GCM buffers the whole archive in memory. To avoid exhausting memory
> on very large backups, GCM refuses archives larger than
> `encryption.gcm_max_megabytes` (default 1024) with a clear error — use
> `aes-256-cbc` for large backups, or raise the limit.

### The key

The key must be **32 bytes**. A `base64:` prefix is decoded automatically, so you
can reuse the Laravel convention:

```bash
php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

```dotenv
BACKUP_ENCRYPTION=aes-256-gcm
BACKUP_ENCRYPTION_KEY=base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx=
```

> ⚠️ Store the key outside the backup. Without it, an encrypted backup cannot be
> restored. The key is never written to the manifest or logs.

## Checksums

Every backup records a checksum (SHA-256 by default; SHA-512 optionally) of:

- the **whole archive** — verified before every restore, and by `backup:verify`;
- **each individual file** — recorded in the manifest for per-file verification.

```php
'integrity' => ['checksum' => 'sha256'],  // sha256 | sha512
```

Comparison is constant-time to avoid timing side-channels.

## Signing

> **Pro feature.** HMAC manifest signing requires
> [Backup Manager Pro](13-pro-and-ui.md). The free core keeps the `Signer`
> contract but binds no implementation: with signing enabled but Pro absent a
> backup fails closed, so it is never written unsigned while you believe it is
> signed.

An optional keyed HMAC signature authenticates the manifest itself — proving the
backup was produced by a holder of the signing key and has not been altered:

```php
'integrity' => [
    'signing' => [
        'enabled' => true,
        'key' => env('BACKUP_SIGNING_KEY'),  // defaults to the encryption key
    ],
],
```

`backup:verify` reports both the archive checksum and the signature status.

## The manifest

Every backup ships a `*.manifest.json` sidecar — a self-describing record that
makes the backup verifiable independently of this package. It contains:

- schema version, profile name, backup type, creation timestamp;
- the **environment**: app name & version, Laravel version, PHP version,
  environment name, operating system;
- the **drivers** used: compression, encryption, checksum algorithms;
- the **databases** dumped (connection, driver, database name, archived path);
- the **archive** size and checksum;
- **every file**: archive path, size, checksum, symlink info;
- the **migrations** that had run when the backup was taken;
- an optional **signature**.

Because it is a sidecar, you can read it and verify the archive **without
decrypting** — it is plain JSON you can open in any editor.

### Matching a dump to a codebase

A database dump only fits a codebase whose schema expectations match it, and the
app version does not establish that — two commits can share a version string and
differ by a migration. The manifest therefore records the applied migrations:

```json
"migrations": {
  "ran": [
    "2024_01_01_000000_create_users_table",
    "2024_02_01_000000_add_roles_to_users"
  ]
}
```

`MigrationState` answers the questions that matter before a restore:

```php
$manifest = app(BackupRepository::class)->readManifest($backup);
$current  = app(MigrationProbe::class)->capture();

$current->ahead($manifest->migrations);   // migrations the code has, the dump doesn't
$manifest->migrations->ahead($current);   // migrations the dump has, the code doesn't
$current->matches($manifest->migrations); // exact fit
```

The first list means restoring will leave the schema behind the code — usually
fixable by migrating afterwards. The second means the dump was taken on a newer
schema than the code expects, which migrating cannot undo.

Reading the migrations never fails a backup: an application with no database, an
unreachable one, or one that has never migrated records `null` instead. Manifests
written before schema 1.2 also read back as `null` — *not recorded*, which is not
the same as *none had run*.

## Verifying a backup

```bash
php artisan backup:verify                    # newest backup
php artisan backup:verify my-backup.tar.gz.enc
```

This downloads the archive, recomputes its checksum against the manifest and, if
signed, verifies the signature — the lightweight restorability test. Restores
also perform this check automatically (disable with `--no-verify`).
