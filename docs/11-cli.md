---
title: CLI reference
description: "All commands accept --profile= to target a specific profile (default: the configured default profile)."
key: cli
---
# CLI reference

All commands accept `--profile=` to target a specific profile (default: the
configured `default` profile).

## `backup:run`

Create a new backup.

```bash
php artisan backup:run
php artisan backup:run --profile=nightly
```

## `backup:list`

List stored backups for a profile's destination.

```bash
php artisan backup:list
php artisan backup:list --profile=nightly --disk=s3
```

| Option | Description |
|--------|-------------|
| `--disk=` | Override the destination disk to inspect |

## `backup:restore`

Restore a backup. Asks for confirmation unless `--force`.

```bash
php artisan backup:restore
php artisan backup:restore my-backup.tar.gz.enc --force
```

| Option | Description |
|--------|-------------|
| `--disk=` | Override the destination disk |
| `--database` | Restore databases only |
| `--files` | Restore files only |
| `--only=*` | Restore only files under these absolute path prefixes |
| `--no-verify` | Skip archive integrity verification |
| `--force` | Do not ask for confirmation |

## `backup:verify`

Verify a backup's integrity against its manifest (checksum + signature) without
restoring. Exits non-zero on failure.

```bash
php artisan backup:verify
php artisan backup:verify my-backup.tar.gz.enc
```

## `backup:clean`

Apply the retention policy, deleting backups that fall outside it.

```bash
php artisan backup:clean
php artisan backup:clean --dry-run
php artisan backup:clean --profile=nightly --disk=s3
```

Only the profile's own backups are considered, even where several profiles share
a disk and path. See [Retention](08-retention.md).

| Option | Description |
|--------|-------------|
| `--disk=` | Only clean this destination disk |
| `--dry-run` | Show what would be deleted without deleting |
| `--sync` | Run immediately even when queued backups are enabled |

## `backup:monitor`

Report the health of a profile's backups. Exits non-zero if any destination is
unhealthy.

```bash
php artisan backup:monitor
php artisan backup:monitor --profile=nightly
```

| Option | Description |
|--------|-------------|
| `--sync` | Run immediately even when queued backups are enabled |

A destination that cannot be read at all — an expired token, a network outage —
is reported as unhealthy with the underlying error, not raised as a failure of
the whole report.

## `backup:mcp`

Serve the Model Context Protocol server on stdin/stdout, so an AI assistant can
inspect, configure, run and verify backups. See
[AI assistants (MCP)](14-mcp.md).

```bash
php artisan backup:mcp
php artisan backup:mcp --read-only
```

| Option | Description |
|--------|-------------|
| `--allow-writes` | Allow configuration writes for this session |
| `--deny-writes` | Forbid configuration writes for this session |
| `--allow-destructive` | Allow deleting backups for this session |
| `--read-only` | Expose only tools that change nothing |

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | success / healthy |
| `1` | failure / unhealthy / verification failed |
