---
title: Getting started
description: "This tutorial takes you from nothing to a real, verified backup that you can restore — in about ten minutes."
key: getting-started
---
# Getting started

This tutorial takes you from nothing to a **real, verified backup that you can
restore** — in about ten minutes. Follow it top to bottom; each step is a command
you run and the output you should see.

By the end you'll have created a backup, inspected it, confirmed its integrity,
restored it, and know how to make it production-ready.

## What you'll do

1. Install the package into a Laravel app.
2. Create your first backup.
3. See what was stored.
4. Verify the backup can be trusted.
5. Restore it — safely.
6. Make it production-ready: encrypt it, send it off-site, and run it on a schedule.

## Before you start

You need an existing **Laravel 12 or 13** application and **PHP 8.3+**. If you
back up a database, its command-line tools (for example `mysqldump` for MySQL)
must be installed. The full list is on the [Installation](01-installation.md)
page — but for a first run against SQLite or files only, you need nothing extra.

## 1. Install the package

From your application's root, require the package and publish its config:

```bash
composer require nyoncode/laravel-backup-manager
php artisan vendor:publish --tag="backup-manager-config"
```

That's it — Laravel registers the package automatically, and you now have a
`config/backup-manager.php` file. The defaults back up your whole application
(database + files) to local storage, which is perfect for this tutorial.

## 2. Create your first backup

Run the default backup:

```bash
php artisan backup:run
```

You'll see a summary of what was captured:

```text
   INFO  Running backup for profile [default]….

  Archive ............ default-2026-07-19-020000-a1b2c3.tar.gz
  Mode ............... Archive
  Size ............... 4.72 MB
  Databases .......... 1
  Files .............. 128
  Destinations ....... local

   INFO  Backup completed successfully.
```

You just created your first backup. It lives on the `local` disk under the
`backups/` folder.

## 3. See what was stored

List the backups the package knows about:

```bash
php artisan backup:list
```

Each backup is **two files**: the archive itself (a compressed `.tar` you can
optionally encrypt) and a `*.manifest.json` next to it. The manifest is a plain,
unencrypted record of what's inside — the environment, the checksum of the
archive, and a hash of every file — so a backup can be checked and trusted on its
own, even without this package.

## 4. Check it can be trusted

A backup is only worth having if it isn't corrupted. Verify the newest one
against its manifest:

```bash
php artisan backup:verify
```

```text
   INFO  Verifying backup [default-2026-07-19-020000-a1b2c3]….

  Archive checksum ... OK
  Files ............. 128/128 verified

   INFO  Backup is valid.
```

## 5. Restore it

Restoring puts the backup's contents back. Try it:

```bash
php artisan backup:restore
```

The command asks you to confirm, then verifies the archive **before** touching
anything. By default it also takes a pre-restore snapshot and **rolls everything
back automatically if the restore fails** — so a bad restore never leaves you
worse off than before. You can restore just the database or just files; see
[Restore](07-restore.md) for the options.

> **Try restores on staging first.** Restoring overwrites current data with the
> backup's data — that's the whole point, so run it where you mean it.

## 6. Make it production-ready

A local, unencrypted backup is a great start. Three changes turn it into
something you'd trust in production:

- **Encrypt it.** Set an algorithm and a key so the archive is unreadable at
  rest. See [Security & the manifest](06-security.md).
- **Send it off-site.** A backup on the same server as your app disappears with
  the server. Add an S3 (or FTP/SFTP/…) disk to the profile's `destinations` and
  write to both at once. See [Storage destinations](05-storage.md).
- **Run it automatically.** Turn on the built-in schedule so backups, cleanup and
  health checks happen without you. See [Scheduling & monitoring](10-scheduling.md).

For example, in `config/backup-manager.php`:

```php
'profiles' => [
    'default' => [
        // …
        'destinations' => ['s3', 'local'],   // off-site + a local copy
    ],
],
'encryption' => [
    'algorithm' => 'aes-256-gcm',
    'key' => env('BACKUP_ENCRYPTION_KEY'),   // 32 bytes, base64: accepted
],
'schedule' => ['enabled' => true],
```

## Where to go next

- [Configuration](02-configuration.md) — every option, explained section by section.
- [Databases](03-databases.md) and [Files](04-files.md) — choose exactly what to capture.
- [Retention](08-retention.md) — keep history under control so backups don't fill the disk.
- [Pro & the UI dashboard](13-pro-and-ui.md) — incremental backups, monitoring and a live web dashboard.
