# Changelog

All notable changes to `nyoncode/laravel-backup-manager` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.1]

### Fixed

- **Notifications read the configuration the worker booted with, under Octane.**
  Subscribing the listeners at boot built `SendBackupNotifications`, and with it
  the `BackupNotifier` singleton and its mail channel, each holding the config
  repository (and mailer) of the moment the application booted. Under a
  long-lived worker that is one object for the worker's whole life, so a
  notification read the worker's config instead of the running request's. The
  notifier now resolves config and channels through the current container on
  every use, and the listeners are registered by class and method, so nothing is
  built at boot — which also takes the notifier off every PHP-FPM request's boot.
  `new BackupNotifier($config, $channels)` still works; both arguments may now be
  closures.

## [1.3.0]

### Fixed

- **Retention judged every backup in the path, not the profile's own.**
  `RetentionManager::apply()` listed the whole destination and deleted whatever
  fell outside the keep-set, without ever asking which profile a backup belonged
  to. Since `path` defaults to `backups` for every profile, two profiles sharing
  a disk is the *default* arrangement — so a nightly `backup:clean` run under the
  default profile's policy pruned the fortnightly full backups sitting beside it.
  Nothing reported it; `keep_latest = 5` on the other profile simply never held.
  Every stored backup now carries the profile from its manifest, and both
  retention engines count and delete only their own. Backups whose manifest
  predates the tag (or cannot be read) still count as the asking profile's, so an
  upgrade keeps cleaning up exactly what it cleaned up before.

### Added

- **`backup:clean` can go to the queue**, symmetrically with `backup:run`: with
  `BACKUP_QUEUE=true` it dispatches the new `CleanBackupsJob` and `--sync` forces
  it inline. Scheduled without one, a cleanup that reference-counts an entire
  blob pool ran inside the scheduler's process on the web machine — no worker
  memory limit, nothing in `failed_jobs`, invisible to any queue dashboard.
  `--dry-run` never queues: its whole product is the preview it prints.
- **`queue.restore_timeout`** (`BACKUP_QUEUE_RESTORE_TIMEOUT`) for jobs that
  restore. A restore downloads, decrypts, unpacks and takes its own pre-restore
  snapshot first, so it routinely outlasts the backup that produced it. Null —
  the default — uses `queue.timeout`.
- `CleanupManager`, the one place a profile's retention policy is applied across
  its destinations. The CLI, the queue, the dashboard and the MCP tool had each
  grown their own copy of the same two-engine sequence; they now share this one,
  so none of them can drift on which backups a policy governs.
- `StoredBackup::$profile` and `StoredBackup::belongsTo()`.
- `CleanupReport`, describing what retention did to one destination.

### Changed

- **Listings are scoped to their profile.** `backup:list`, `backup:verify` and
  `backup:restore` no longer offer up a neighbouring profile's backups from a
  shared destination — the set you list is now the set that profile's retention
  governs.
- An empty or null `queue.queue` falls back to `backups` instead of dispatching
  onto a queue named `''`, which no worker listens on.
- `RetentionManager::apply()` and the `SnapshotDriver::applyRetention()` contract
  take an optional trailing `?string $profile`. Existing calls keep working
  unchanged (null means "every backup in the path", the old behaviour); a custom
  `SnapshotDriver` implementation needs the new parameter in its signature.

### Changed

- Test suite moved to **Pest 4**, with Pest 5 supported alongside it
  (`^4.0|^5.0`). Which one is installed follows the environment: Pest 5 needs
  PHP 8.4 and Symfony 8, so a Laravel 12 install stays on Pest 4 and gets the
  newer major only with Laravel 13. Both were run against the full suite.

## [1.2.0] - 2026-08-22

### Fixed

- **MariaDB hosts without the legacy `mysql*` symlinks could not be backed up.**
  Both the dumper and the restorer asked for `mysqldump` / `mysql` by name,
  while MariaDB 11 renamed its clients to `mariadb-dump` / `mariadb` and left
  the old names as deprecated symlinks that several distributions and container
  images no longer ship — so a backup failed with "The [mysqldump] binary could
  not be found", despite MariaDB being a documented driver. Each connection now
  asks for the clients its own driver names, best first, so either spelling
  works; where both are installed the one matching the server wins, since
  MySQL's `mysqldump` can emit SQL a MariaDB server will not read back.
  `mariadb-dump` and `mariadb` are configurable like the other binaries
  (`BACKUP_MARIADB_DUMP_PATH`, `BACKUP_MARIADB_PATH`).

### Added

- **An MCP server, so an AI assistant can set backups up for you**
  (`php artisan backup:mcp`). It speaks the Model Context Protocol over
  stdin/stdout with no extra dependency, and exposes twelve tools: a setup
  guide, an overview of the installation, the environment (disks, connections,
  binaries, free space), the resolved profiles, the full configuration
  reference, a validator, the stored backups, a retention preview, and the
  operations themselves — configure, run, verify, prune. The point is that an
  assistant reads *this* installation before it proposes anything, instead of
  inventing a disk name or a config key that sounds plausible.

  Writing is bounded on every side. `backup_configure` accepts only keys the
  reference documents, with values of the declared type; it defaults to a dry
  run that returns both the exact edits and the findings the result *would*
  have, and refuses to apply a change that would leave errors behind unless
  explicitly forced. A setting the config file reads through `env()` is written
  to `.env` rather than baked in as a literal, secrets are written but never
  echoed back, the config file is edited by token so its comments survive, the
  edited source is re-parsed before it is written, the previous file is copied
  aside, and the write itself is atomic.

  Permissions are per access level, not per tool name, so a tool an add-on
  contributes is governed by the same rules: reading and running backups is
  always allowed, configuration writes are on by default,
  **deleting backups is off** until `BACKUP_MCP_ALLOW_DESTRUCTIVE=true`, and the
  server refuses to start outside `mcp.environments` (`local` by default).
  Restore is not exposed over MCP at all — it overwrites a live application, so
  it stays a deliberate human act. See [docs/14-mcp.md](docs/14-mcp.md).

- **`ConfigValidator` — a standing check on the configuration**, reachable from
  the MCP server and usable on its own. It reports what would fail (a
  destination disk that does not exist, a missing dump binary, encryption
  enabled without a key, a capability that needs Pro) *and* what would quietly
  protect nothing: no off-site destination, no schedule, nobody on the failure
  mail, a `keep_latest` below 1 that would delete every backup it finds. Each
  finding names the key and the fix.

- **Configuration writing primitives** used by the MCP server and available to
  anything else that needs them: `ConfigReference` (the package's own
  configuration described as data), `EnvFile` (comment-preserving `.env`
  upserts), `PhpConfigFile` (token-level edits to a published config file),
  `ConfigWriter` (routes each key to the right file), `BackupFinder` and
  `Edition`.

- **The manifest records which migrations had run** (`migrations.ran`, manifest
  schema 1.2). A database dump only fits a codebase whose schema expectations
  match it, and the app version does not establish that — two commits can share
  a version string and differ by a migration. The dump has always contained the
  `migrations` table, but only after decrypting and unpacking it; the same fact
  now sits in the sidecar, so a backup can be checked against a codebase, and two
  backups diffed against each other, without touching the archive. `MigrationState`
  exposes `latest()`, `count()`, `ahead()` and `matches()`. Reading is fail-soft:
  no database, an unreachable one or one that never migrated records null rather
  than failing the backup, and manifests written before 1.2 read back as null —
  "not recorded", not "none had run".

- **`DeleteManager` — targeted deletion of named backups.** Retention prunes on
  a policy; this removes exactly what the caller names, for a corrupted archive
  or a run kept by mistake. Guarded rather than trusted: it refuses outright if
  the deletion would leave the destination with no backup at all, and every name
  is resolved before anything is removed, so an unknown one aborts the whole
  call instead of deleting half a selection. Deleting an incremental snapshot
  removes only its manifest — its blocks stay in the shared pool until a
  retention run garbage-collects them, because other snapshots may still
  reference them. Supports a dry run.
- **`BackupDeleted` event.** Dispatched per deleted backup, carrying an optional
  actor so a listener can record who removed what. Retention stays silent; only
  a targeted deletion fires it.
- The package now contributes a **Backup Manager** section to `php artisan
  about`, reporting the default profile, encryption, manifest signing, whether
  the incremental (Pro) engine is active, and scheduling.

## [1.1.2] - 2026-08-06

### Changed

- Widened the `symfony/finder` and `symfony/process` constraints to
  `^7.0|^8.0`, so the package installs alongside Symfony 8.

## [1.1.1] - 2026-07-20

### Security

- **Manifest signature is now enforced on restore.** When signing is enabled
  (a `Signer` is bound), restore refuses any backup whose manifest signature is
  missing or invalid instead of trusting the checksum alone — closing a gap where
  a tampered manifest, or one with its signature stripped, could still be
  restored. Environments without signing keep the checksum-only behaviour, so
  unsigned backups still restore there; `--no-verify` remains the explicit
  opt-out.
- **Database dump path now goes through the traversal guard.** `RestoreManager`
  resolves each manifest-declared database dump path with `Support\SafePath`, so
  a hostile `archived_path` (`../../…`) can no longer make restore read and pipe
  an arbitrary host file into the database client.
- **`backup:upgrade` keeps the licence token off the process argv.** Credentials
  are written straight into `auth.json` (merging any existing entries, mode
  `0600`) rather than via `composer config --auth`, so the token is no longer
  visible to other local users through `ps`/`/proc`.

### Fixed

- Corrected the Pro package name to `nyoncode/laravel-backup-manager-pro`
  throughout config, the `backup:upgrade` command, runtime messages and docs so
  activation targets the actually-published package.

## [1.1.0] - 2026-07-19

### Security

- **Restore path-traversal (tar-slip) hardening.** Archive extraction and
  incremental snapshot materialisation now refuse any entry whose name would
  resolve outside the extraction directory (`../…` escapes). A new
  `Support\SafePath` guard is the single choke point for both write paths, so a
  corrupt or hostile backup aborts the restore fail-closed rather than writing a
  file elsewhere on the host.

### Added

- **Incremental & deduplicated backups (roadmap #1).** A profile can now set
  `'mode' => 'incremental'` (orthogonal to its `type` scope) to store a snapshot
  instead of a self-contained archive:
  - A content-addressed `BlobStore` (`DiskBlobStore`) over any Laravel disk.
    Blobs are keyed by their content checksum
    (`{path}/blobs/{algorithm}/{ab}/{digest}`), so identical file contents are
    stored exactly once and unchanged files carry forward for free across
    snapshots — the deduplication that makes a run incremental.
  - A `SnapshotRepository` owning the `snapshots/` + `blobs/` layout (which
    coexists with archive backups under the same path), and `StoreSnapshot` /
    `BuildSnapshotManifest` pipeline stages selected by the new `BackupMode`.
  - A `SnapshotDiff` value object classifying the tree against the previous
    snapshot (added / changed / unchanged / removed); `backup:run` reports the
    new-vs-reused breakdown.
  - **Restore** of incremental snapshots: `backup:restore` reassembles a
    snapshot by fetching each file's blob by content hash into a staging area,
    then reuses the existing database/file restore, selective (`--only`),
    database-/files-only, and rollback-safety paths unchanged. Verified restores
    check the snapshot's file-index anchor and every blob's checksum. Because
    addressing is by content, restoring an older snapshot faithfully returns its
    historical content even after newer snapshots exist.
  - `backup:list` now shows a **Mode** column and lists snapshots alongside
    archives; `backup:restore <name>` and the newest-first default resolve both.
  - **Retention & garbage collection:** `backup:clean` now prunes snapshots by
    the same GFS/simple + age/size policy as archives, then reclaims the blob
    pool by reference-counted mark-and-sweep — a blob is deleted only when no
    surviving snapshot references it, so pruning one snapshot never breaks
    another that shares content. The sweep is **fail-closed**: if any surviving
    manifest cannot be read, no blob is deleted. `--dry-run` previews both the
    pruned snapshots and the reclaimable pool without touching anything.
  - **Blob-pool compression & encryption:** when a profile configures them,
    snapshot blobs are stored compressed and then AES-256 encrypted, but still
    keyed by their *plaintext* content hash, so deduplication is fully preserved.
    New content is encoded at most once per run (never for already-deduplicated
    content); restore and verify decode each blob and check it against the
    plaintext hash. Treat the algorithm and key as fixed for a pool's lifetime,
    like a repository password.
  - **Verification & monitoring:** `backup:verify` verifies a snapshot by
    confirming its file-index anchor, that every referenced blob is present, and
    that each blob (decrypted where needed) hashes to its recorded value.
    `backup:monitor` and `backup:list` count snapshots alongside archives, so a
    snapshot-only profile is no longer reported as having no backups.
  - Manifest schema bumped to **1.1** (adds `mode`; older manifests read back as
    `archive`).

  Archive backups are unaffected and remain the default.

- **`backup:upgrade` command.** Activate a Pro licence from the CLI: it
  configures the private Pro Composer repository and its credentials from the
  token you pass, then installs `nyoncode/laravel-backup-manager-pro` (whose provider is
  auto-discovered — no code change). `--dry-run` previews every step with the
  token masked; if anything fails, the exact manual commands are printed so the
  upgrade is never left half-done.

### Changed

- **Incremental backups are now a Pro feature.** The snapshot engine (blob pool,
  snapshot repository/codec, snapshot pipeline stages and reference-counted
  retention) has moved to the `nyoncode/laravel-backup-manager-pro` package. The free
  core keeps a single `SnapshotDriver` extension point and ships a null driver:
  it can still **read and list** snapshots (their manifests are plain JSON) but
  refuses to create, restore or verify one with a clear "requires Pro" message.
  Installing the Pro package binds the real pool-backed driver and restores the
  full incremental behaviour with no configuration change. Archive backups are
  entirely unaffected.
- **Manifest signing (HMAC tamper-evidence) is now a Pro feature.** The core
  keeps the `Signer` contract, the manifest signature field and signature
  *verification*, but the `HmacSigner` implementation has moved to
  `nyoncode/laravel-backup-manager-pro`. Checksum integrity (build + verify) is
  unchanged and remains in the free core. When signing is enabled without Pro
  installed, the core now **fails closed** — a backup aborts with a clear
  "signing requires Pro" error rather than silently producing an unsigned
  backup a user believes is signed. Installing Pro binds the real signer and
  restores signing with no configuration change.
- **GFS retention and total-storage eviction are now Pro features.** The free
  core keeps the `simple` (keep-latest) strategy and the age cap
  (`max_age_days`); the Grandfather-Father-Son strategy and total-storage
  eviction (`max_storage_megabytes`) have moved to `nyoncode/laravel-backup-manager-pro`.
  Strategies are now resolved by name from a `RetentionStrategyRegistry`, so
  selecting `gfs` without Pro **fails closed** — retention keeps every backup
  rather than prune under a policy it cannot honour — and a storage cap set
  without Pro is simply not enforced (the fail-safe direction: more backups
  kept). The **default strategy is now `simple`** (was `gfs`); installing Pro
  re-enables `gfs` and storage-cap eviction with no other change.
- **Health monitoring, backup history and advanced notifications are now Pro
  features.** The free core keeps structured **logging** of every lifecycle
  event and **failure notifications by e-mail**. Moved to
  `nyoncode/laravel-backup-manager-pro`: the `backup:monitor` command and health
  reporting (`MonitorManager`, `HealthReport`, the `BackupUnhealthy` event), the
  persisted **history** table (`BackupHistory` + its migration and listener),
  and the **Slack channel** plus **unhealthy-destination alerts**. Notifications
  now fan out through a tagged `NotificationChannel` set — the core registers
  mail, Pro adds Slack — so a failure reaches both channels once Pro is
  installed. The monitoring config keys stay in the core schema but are inert
  without Pro.

## [1.0.0] - 2026-07-16

Initial release.

### Databases

- Backups for **MySQL, MariaDB, PostgreSQL and SQLite**.
- Per-table `exclude_tables` and `schema_only_tables` (structure kept, rows
  skipped).
- Credentials passed through the environment, never the command line.

### Files

- Multiple source roots with include/exclude filters (path prefixes and globs).
- Hidden-file handling and symlink metadata (recreated on restore).

### Archive, compression & encryption

- Self-contained **streaming TAR archiver** with GNU extensions — no 8 GiB
  per-file limit, no 100-byte path limit, flat memory usage.
- Streaming compression: `gzip`, `bzip2`, `zstd`, or `none`.
- **AES-256** encryption: CBC (streamed, any size) and GCM (authenticated, with an
  in-memory size guard via `encryption.gcm_max_megabytes`).

### Integrity & the manifest

- SHA-256 / SHA-512 checksums for the archive and every file.
- Optional HMAC signing of the manifest.
- A rich, self-describing JSON **manifest** sidecar (environment, drivers, archive
  checksum, per-file hashes) — verifiable without decrypting the archive.

### Storage

- Any Laravel filesystem disk as a destination: Local, S3-compatible, FTP, SFTP,
  Azure Blob, Backblaze B2, WebDAV.
- Write to multiple destinations in one run; streamed uploads; cleanup of partial
  artifacts on failure.

### Restore

- Full, database-only, files-only and selective restore.
- Integrity verification against the manifest before extracting.
- **Snapshot-based rollback safety net** (saga/compensation) with atomic file
  writes; opt out with `--unsafe`.

### Retention

- Grandfather-Father-Son and simple (keep-latest) strategies.
- Age (`max_age_days`) and total-size (`max_storage_megabytes`) caps.
- `--dry-run` preview.

### Scheduling, queues & monitoring

- Laravel Scheduler integration with `withoutOverlapping`.
- Queued backups via `RunBackupJob` (`--sync` to force synchronous).
- Health monitoring, lifecycle events, an optional `backup_history` table, and
  mail / Slack notifications.

### CLI

- `backup:run`, `backup:list`, `backup:restore`, `backup:verify`, `backup:clean`,
  `backup:monitor`.

### Quality

- 175+ tests (unit, feature and real-database + S3 integration), PHPStan level 8,
  Laravel Pint. CI across PHP 8.3 / 8.4, Laravel 12 / 13, Windows, and MySQL /
  MariaDB / PostgreSQL / MinIO service containers.

[Unreleased]: https://github.com/nyoncode/laravel-backup-manager/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/nyoncode/laravel-backup-manager/compare/v1.1.2...v1.2.0
[1.1.2]: https://github.com/nyoncode/laravel-backup-manager/compare/v1.1.1...v1.1.2
[1.1.1]: https://github.com/nyoncode/laravel-backup-manager/compare/v1.1.0...v1.1.1
[1.1.0]: https://github.com/nyoncode/laravel-backup-manager/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/nyoncode/laravel-backup-manager/releases/tag/v1.0.0
