# Changelog

All notable changes to `nyoncode/laravel-backup-manager` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.0] - 2026-11-13

### Upgrade notes

- **Encrypted (AES-256-CBC) backups written before 1.4 restore only when signed,
  or with `backup:restore --allow-legacy-cbc`.** They carry no authentication
  tag, so nothing else can show they were not modified. `backup:verify` still
  reads them.

- **Restoring a database from an unsigned backup needs a client that locks
  down** (MariaDB 10.5.25+/11.x, MySQL 8.4.6+, psql 13.22/14.19/15.14/16.10/
  17.6+) — or signed backups, or `backup:restore --trust-unsigned-dump`.
  Otherwise the restore is refused with instructions. Files-only restores are
  unaffected.

- **`RestoreManager::restore()` takes the profile being restored for** as an
  optional third argument; pass it so confinement uses that profile's include
  paths and a backup from another profile is refused.

- **Encrypted archives written by 1.4 cannot be read by older versions.** New
  AES-256-CBC archives carry an authentication tag (header id `3`); restore them
  with 1.4 or later. Archives from earlier versions keep restoring.

- **Restore is confined to the profile's include paths by default.** A backup
  whose files lie elsewhere (the app moved, a new release directory, a renamed
  profile) is refused until you run `backup:restore --allow-outside` or set
  `BACKUP_RESTORE_CONFINE=false`.

- **Restore aborts when its safety net cannot be taken** (pre-restore database
  dump or file copy). `--unsafe` remains the deliberate opt-out.

- **`backup:verify` fails unsigned backups while signing is enabled** — backups
  taken before signing was turned on now report a missing signature.

- **Only scheduled profiles run on a schedule.** Non-default profiles need their
  own `profiles.*.schedule`; the default profile keeps using `schedule.backup`.

- **Listings ignore files not named like this package's archives.** Rename or
  remove anything you were relying on being counted as a backup.

- Manifest schema is now `1.3` (adds `files[].mode`); older manifests read and
  verify unchanged.

### Added

- `Support\BackupSchedule`: which profiles run when, the next run time and the
  longest gap between runs of a cron expression (what monitoring derives its
  threshold from). `profiles.*.monitoring.max_backup_age_hours` overrides the
  derived threshold per profile. `ConfigValidator` checks per-profile cron
  expressions, thresholds and signing keys, and reports profiles nothing schedules.

### Fixed

- **Only the default profile was ever scheduled.** `backup:run` and
  `backup:clean` were registered without `--profile`, so every other profile was
  neither backed up nor cleaned by the scheduler. Profiles now take their own
  cron expression (`profiles.*.schedule`); the default profile keeps falling
  back to `schedule.backup` and keeps its bare command. Retention is scheduled
  for every scheduled profile (and, as before, for the default profile).

- **Retention could delete every backup.** When backups had been failing for
  longer than `max_age_days` — or the newest one alone exceeded the storage cap —
  the keep-set came out empty and everything was pruned. The newest backup now
  always survives.

### Security

- **An unsigned database dump is restored only through a locked-down client.**
  Inspecting a dump's text cannot rule out every way a tampered one reaches
  mysql's or psql's own commands (a function or trigger the dump defines can
  change how the client reads the rest). A dump is now restored only when the
  backup is signed and verified, or when the client refuses its own commands:
  MariaDB `--sandbox`, MySQL 8.4.6+ `--commands=OFF`, or psql 13.22 / 14.19 /
  15.14 / 16.10 / 17.6+ running the dump inside a `\restrict` whose key only
  the restore knows. Otherwise the restore is refused before anything changes;
  `backup:restore --trust-unsigned-dump` (or
  `BACKUP_RESTORE_TRUST_UNSIGNED_DUMPS=true`, not writable over MCP) restores a
  dump you trust. Restorers declare the capability through
  `Contracts\Database\LocksDownClient`.

- **Restore confinement resolves real paths and trusts the caller's profile.**
  Paths are compared after resolving every symlink on the host, and links are
  recreated only after all regular files, so neither an existing link nor a
  chain of links in the backup can carry a write outside the profile. The
  include paths come from the profile being restored for
  (`RestoreManager::restore(…, $profile)`, passed by `backup:restore` and the
  dashboard); a manifest naming another profile is refused.

- **The untagged pre-1.4 CBC format is decrypted only for an authenticated
  backup.** Archives (and, in Pro, snapshot blobs) in it are accepted when the
  backup's signature verified, or with `backup:restore --allow-legacy-cbc` /
  `BACKUP_ALLOW_LEGACY_CBC=true` (not writable over MCP). The manifest's schema
  version cannot decide this: an unsigned manifest is exactly what an attacker
  rewrites.

- **A restored symbolic link is resolved the kernel's way before it is kept.**
  After it is created, its real path must lie inside the profile — `s -> .`
  makes `s/../x` leave the root although it reads as inside — and an
  unresolvable target that climbs with `..` is removed too.

- **The dump inspector reads statements, not lines.** Statement ends,
  `DELIMITER` and `COPY … FROM stdin` come from the lexer; every mysql named
  command is refused where the client would run it; and a dump is refused when
  it changes how the client reads strings (`NO_BACKSLASH_ESCAPES`,
  `ANSI_QUOTES`, a `sql_mode` from anything but a literal or mysqldump's saved
  value, `standard_conforming_strings` off, a multibyte client character set).
  `\connect` may only name a database; `E'…'` is only an escape string as a
  prefix. These checks are allow-lists: a statement touching `sql_mode`, a
  client character set, `standard_conforming_strings` or `client_encoding`
  passes only in the exact forms mysqldump and pg_dump write (encodings
  compared the way PostgreSQL normalises their names). Every statement of a
  `DELIMITER` batch is judged on its own, quoted identifiers count as the
  names they spell, PostgreSQL settings are found in DO bodies and literals
  too, and `PREPARE`, `EXECUTE`, `CALL` and `… INTO @var` are refused.

- **MCP: keys are set-once and protections cannot be switched off.** An
  encryption or signing key can be set while none is in effect — the signing
  key counts as set while it falls back to the encryption key — never replaced, and
  encryption or signing that is on cannot be turned off through the assistant.

- **Restore writes files only inside the profile.** A restore whose manifest
  holds a file outside the profile's current `files.include` paths — a tampered
  manifest aiming at `~/.ssh` or `/etc`, or an app that has since moved — is
  refused before anything is downloaded or changed, naming the paths (and the
  profile, when it is no longer configured). Roots reached through symlinks are
  matched by their resolved path too, and an include of `/` works. For a trusted
  backup whose paths moved, run `backup:restore --allow-outside` once, or set
  `restore.confine_to_include_paths` (`BACKUP_RESTORE_CONFINE`) to `false`; the
  assistant cannot change it. Symbolic links follow the same setting.

- **A rollback no longer claims success it did not have.** Files are put back
  through a temp file and a rename (a restored read-only file used to block the
  copy silently), and when any rollback step fails the error says the rollback
  was incomplete and lists what to check.

- **A restored SQLite database keeps the live file's mode and, when restoring as
  root, its owner** — a root restore no longer leaves the app with a read-only
  database.

- **Restored files keep their permissions.** The manifest (schema `1.3`) records
  each file's mode, and restore applies it before the file is moved into place,
  so a `.env` or private key no longer comes back world-readable. Rollback puts
  modes back too. Older manifests are unchanged byte for byte, so their
  signatures still verify.

- **SQLite backups are consistent in WAL mode.** The database is now captured
  with `VACUUM INTO`, which includes committed pages still in the write-ahead
  log; a plain file copy missed them. Restore swaps the file in atomically and
  removes the replaced database's stale `-wal`/`-shm`, which SQLite would
  otherwise replay over the restored data.

- **Bounded memory on restore.** GCM decryption refuses archives over
  `gcm_max_megabytes` (as encryption already did), a tar long-name record over
  64 KiB is refused, and an overflowing base-256 size field is rejected.

- **`backup:upgrade` refuses to send the licence token over plain HTTP**, reads
  it from `BACKUP_PRO_TOKEN` or a prompt, and warns when it was passed as an
  argument (and so sits in the shell history).

- **AES-256-CBC archives are now authenticated (encrypt-then-MAC).** New CBC
  archives carry an HMAC-SHA256 tag (header id `3`) over the header, IV and
  ciphertext, keyed by an HKDF-derived subkey of the encryption key — nothing
  new to configure. The tag is checked in full before a byte is decrypted, so a
  modified archive is refused instead of decrypted into something plausible, and
  no padding error is reachable on unauthenticated input. Archives written
  before 1.4 (id `1`) still decrypt, with a logged warning; only manifest
  signing (Pro) can tell those from a forgery. The algorithm in an archive's
  header must now match the one expected from its manifest. **Downgrade note:**
  versions before 1.4 cannot decrypt CBC archives written by 1.4.

- **Restored dumps can no longer run client commands.** `mysql` and `psql` act
  on more than SQL — `\! cmd` runs a shell command, `source`/`\i` read files,
  `tee`/`\o` write them — so a tampered dump turned write access to backup
  storage into code execution on the app host. Dumps are now lexed before
  import (strings, comments, dollar quotes and `COPY` data understood) and
  refused when they carry any client command the dumpers never write. The
  MySQL/MariaDB client additionally gets `--sandbox` or `--system-command=OFF`
  when it supports them.

- **A restore no longer proceeds without its safety net.** When the
  pre-restore snapshot of a database cannot be taken (timeout, full disk,
  missing client) — or a file cannot be copied aside — the restore now aborts
  before changing anything, instead of overwriting with no way back. A database
  that does not exist yet is still skipped; `--unsafe` remains the deliberate
  opt-out.

- **Strangers' files are never treated as backups.** Listings admit only file
  names this package writes (`{profile}-{Y-m-d-His}-{hex}.tar…`), so a file
  someone else keeps in the same directory — or a whole bucket when `path` is
  empty — is neither counted nor pruned by retention.

- **Restore no longer recreates symbolic links that leave the profile.** A link's
  target comes from the plaintext (and, without Pro signing, unauthenticated)
  manifest, so a tampered manifest could plant `public/r -> /` in a web root.
  A link is now recreated only when its target resolves inside the profile's
  current `files.include` paths; anything else is skipped, logged, listed in
  `RestoreResult::$skippedLinks` and printed by `backup:restore`.
  Manifest paths are normalised before `--only` matching (so `app/../../etc`
  cannot slip past a selective restore), and archive entries are read from the
  extract directory through `SafePath`.

- **The MCP server can no longer widen its own access.** `backup_configure`
  refuses `mcp.*`, `binaries.*`, `pro.*` and `working_directory` — keys that
  decide what the assistant may do, which programs run, where the licence token
  is sent and where plaintext dumps are staged — even with `force`.

- **`.env` values are written without variable expansion.** A value containing
  `$` was double-quoted, which phpdotenv expands: `https://x/?k=${APP_KEY}` would
  have mailed the app key to whoever owns the URL. Such values are now
  single-quoted, and ones that cannot be written literally are refused.

- **Table names that read as options are refused.** `schema_only_tables` reach
  `mysqldump` positionally; a name starting with `-` (or holding control
  characters) now fails profile resolution.

- **Manifest digests must be hexadecimal.** A digest also addresses a blob in
  the pool, so `../../x` is refused instead of being used as a path.

- **Failed backups no longer leave plaintext dumps behind.** The workspace is
  removed when the pipeline throws, and it is created owner-only (`0700`).

- **`backup:verify` fails an unsigned manifest when signing is enabled**, the
  same rule restore already enforced; stripping the signature no longer makes a
  backup "pass". The CLI says *missing* rather than *invalid* for such a backup
  (`VerificationResult::unsigned()`).

## [1.3.1] - 2026-11-07

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

## [1.3.0] - 2026-10-17

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
