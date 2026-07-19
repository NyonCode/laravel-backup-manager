# Storage destinations

A **destination** is an ordinary Laravel filesystem disk. Because the package
writes through Laravel's filesystem, **any** disk driver works with no
package-specific code: Local, S3-compatible object storage, FTP, SFTP, Azure
Blob, Backblaze B2, WebDAV, and anything else with a Flysystem adapter.

## Selecting destinations

```php
'profiles' => [
    'nightly' => [
        'destinations' => ['s3', 'local'],  // written to BOTH
        'path' => 'backups',                // sub-directory on each disk
    ],
],
```

A backup is streamed to **every** listed disk. The archive is uploaded with a
stream, so large backups do not need to fit in memory.

## Example disks

Define these in `config/filesystems.php` as usual, then reference them by name.

### S3 / S3-compatible (MinIO, DigitalOcean Spaces, …)

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),  // for S3-compatible providers
],
```

Requires `composer require league/flysystem-aws-s3-v3`.

### SFTP

```php
'sftp' => [
    'driver' => 'sftp',
    'host' => env('SFTP_HOST'),
    'username' => env('SFTP_USERNAME'),
    'privateKey' => env('SFTP_PRIVATE_KEY'),
    'root' => '/backups',
],
```

Requires `composer require league/flysystem-sftp-v3`.

### Backblaze B2 / WebDAV / Azure Blob

Install the matching Flysystem adapter and register the disk in
`config/filesystems.php`. No changes are needed in Backup Manager — it just uses
the disk name.

## What is stored

For each backup, two objects are written under `path`:

```text
backups/nightly-2026-07-16-020000.tar.gz.enc     ← the archive
backups/nightly-2026-07-16-020000.manifest.json  ← the manifest sidecar
```

The manifest is stored **unencrypted** next to the archive so you can inspect it
and verify the archive checksum without downloading or decrypting the archive. It
never contains secrets — only which algorithms were used.

## Listing and deleting

`php artisan backup:list` reads the sidecars to list what's stored, and
`php artisan backup:clean` deletes archives (and their sidecars) per the
[retention policy](08-retention.md). Both operate through the `BackupRepository`
contract, so they work identically on every disk.
