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
also perform this check automatically (disable with `--no-verify`). With signing
enabled, a manifest that carries **no** signature fails verification — the same
rule restore applies, since stripping the signature is the cheapest way to dodge
it.

## Hardening in 1.3

Behaviour you get without configuring anything:

- **Signing needs a key.** Signing with an empty key is refused instead of
  producing a signature anyone can forge.
- **Enable signing if you restore from shared storage.** Manifests are otherwise
  unauthenticated plain JSON; an attacker who can write to the destination can
  edit one. Pro signing (above) closes that.
- **Restore keeps symlinks inside your paths.** A symbolic link is recreated only
  if its target stays inside the profile's *current* `files.include` paths.
  Otherwise it is skipped and a warning is logged:

  ```text
  Backup Manager restore skipped the symbolic link [public/leak] -> [/etc]: its target lies outside the profile's include paths.
  ```

- **`--only` matches normalized paths.** Manifest paths are normalized before
  `backup:restore --only` compares them, so an oddly spelled path in a manifest
  cannot dodge a filter.
- **Private workspace.** The temporary workspace is created `0700` and removed
  when a backup fails, so half-written dumps are not left behind.
- **Digests must be hex.** Checksums read from a manifest must be hexadecimal
  strings.
- **Retention keeps the newest backup** even when `max_age_days` or a storage cap
  would remove everything (see [Retention](08-retention.md)).
- **CBC is authenticated.** `aes-256-cbc` archives are now encrypt-then-MAC
  (header id 3): an HMAC-SHA256 tag at the end, keyed by a subkey that HKDF
  derives from your encryption key, is verified *before* a byte is decrypted.
  There is nothing to configure. The algorithm named in the archive header must
  also match the manifest's, or decryption is refused.

  Archives written before 1.3 (header id 1) still decrypt, with a logged
  warning. Nothing authenticates them — only Pro signing protects those against
  forgery or downgrade.

- **Restored SQL dumps are inspected.** Before the client runs, a dump that
  carries `mysql`/`psql` client commands — `\!`, `system`, `source`, `tee`,
  `pager`, or any psql meta-command other than `\connect`, `\restrict` and
  `\unrestrict` — is refused, because such a command runs shell commands or
  touches files on the host. Where the client supports it, the MySQL/MariaDB
  restore also passes `--sandbox` (or `--system-command=OFF`).
- **Listings only count your archives.** A file is treated as a backup only if
  it is named like this package's archives —
  `{profile}-{Y-m-d-His}-{hex}.tar[.gz|.bz2|.zst][.enc]`. Other files in the
  path are never listed, counted or pruned.
- **Restore is confined to your include paths.** A restore whose files lie
  outside the profile's *current* `files.include` paths is refused before
  anything changes. See [Where files are restored](07-restore.md#where-files-are-restored).
- **Permissions are restored.** File modes are recorded in the manifest and
  reapplied on restore, so a private file does not come back world-readable.
- **GCM decryption is bounded.** `aes-256-gcm` buffers the archive to
  authenticate it, so decryption is refused above `encryption.gcm_max_megabytes`
  (`BACKUP_GCM_MAX_MB`, default 1024).
- **Tar long names are capped.** A tar header that declares a long name above
  64 KiB is rejected.
- **`backup:upgrade` protects your licence token.** It refuses a plain-`http`
  repository and reads the token from `BACKUP_PRO_TOKEN` or a prompt (see
  [Pro and UI](13-pro-and-ui.md#upgrading-to-pro)).
- **Slack alerts are https-only (Pro).** The webhook URL must use `https`, the
  request times out after 10 seconds, and the URL is never logged.
- **Restore checks where a write actually lands.** Paths are compared after
  resolving every symlink that exists on the host, and symbolic links are
  recreated only after all regular files — so neither a link already on disk nor
  a link in the backup can carry a write outside the profile. The include paths
  come from the profile you restore for (`backup:restore --profile=…`, the
  dashboard's profile); a backup whose manifest names another profile is refused.
- **The untagged pre-1.3 CBC format needs authentication.** Archives and
  snapshot blobs in it are decrypted only for a signed backup, or with
  `backup:restore --allow-legacy-cbc` (`BACKUP_ALLOW_LEGACY_CBC=true`, not
  writable over MCP) — a rewritten archive cannot pass itself off as an old one.
- **Dumps that change how the client reads them are refused.** Besides client
  commands, a dump is refused when it switches MySQL's `sql_mode` to
  `NO_BACKSLASH_ESCAPES` / `ANSI_QUOTES` (or sets it from anything but a literal
  or mysqldump's saved value), turns PostgreSQL's `standard_conforming_strings`
  off, or selects a multibyte client character set such as SJIS, BIG5 or GBK.
  `\connect` may only name a database — never a host, port or service.

### `.env` and MCP writes

When an assistant configures Backup Manager through the MCP `backup_configure`
tool (see [MCP](14-mcp.md)):

- It can never write `mcp.*`, `binaries.*`, `pro.*` or `working_directory` — not
  even with `force`. An assistant cannot widen its own access or point the package
  at a different binary.
- A value containing `$` is written to `.env` single-quoted, so `${VAR}`
  expansion cannot run. A value that cannot be written safely is refused.
- An encryption or signing key can be set while none is, never replaced: a new
  key would strand every backup made with the old one. Rotate keys by hand.
- Encryption and signing, once on, cannot be turned off by the assistant.
