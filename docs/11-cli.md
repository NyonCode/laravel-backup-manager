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

| Option | Description |
|--------|-------------|
| `--disk=` | Only clean this destination disk |
| `--dry-run` | Show what would be deleted without deleting |

## `backup:monitor`

Report the health of a profile's backups. Exits non-zero if any destination is
unhealthy.

```bash
php artisan backup:monitor
php artisan backup:monitor --profile=nightly
```

## Exit codes

| Code | Meaning |
|------|---------|
| `0` | success / healthy |
| `1` | failure / unhealthy / verification failed |
