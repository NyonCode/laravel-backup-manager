# Files

A backup can include files and folders from your project alongside — or instead
of — your databases. This page covers what gets picked up and how to fine-tune
it.

## Include roots

List the directories (or individual files) to archive:

```php
'files' => [
    'include' => [
        base_path('app'),
        base_path('.env'),
        storage_path('app/public'),
    ],
],
```

Each file is stored in the archive under a `files/` prefix that mirrors its
absolute path (e.g. `files/var/www/app/Models/User.php`), so entries from several
roots never collide and map back cleanly on restore.

## Exclusions

```php
'files' => [
    'exclude' => [
        base_path('vendor'),      // path prefix
        base_path('node_modules'),
        '*.log',                  // fnmatch glob
        storage_path('framework'),
    ],
],
```

A pattern matches when it is a **path prefix** of a file, or when it matches the
path (or its basename) as an **fnmatch glob**. This lets you exclude whole
directories and file types in the same list.

## Hidden files

```php
'files' => ['include_hidden' => true],  // include dotfiles like .env
```

Set to `false` to skip dotfiles and dot-directories.

## Symlinks

```php
'files' => ['follow_symlinks' => false],
```

- **`false` (default)** — a symlink is recorded in the manifest as link metadata
  (its target), without archiving the target's contents. On restore the symlink
  is recreated. This preserves link semantics without duplicating data.
- **`true`** — links are followed and the target's contents are archived as
  regular files.

## Compression

Compression is applied to the whole archive (not per file):

| Algorithm | Requires | Notes |
|-----------|----------|-------|
| `none` | — | fastest, largest |
| `gzip` | built-in | good default |
| `bzip2` | `ext-bz2` | smaller, slower |
| `zstd` | `zstd` binary | best speed/ratio |

```php
'compression' => ['algorithm' => 'gzip', 'level' => 6],
```

Compression is streamed, so memory stays flat regardless of archive size.

## Archive format & limits

Backups are packaged as a **streaming TAR archive** with GNU extensions, written
by the package itself (not PharData). This means:

- **No 8 GiB per-file limit** — large database dumps or media files are supported
  via GNU base-256 size fields.
- **No 100-byte path limit** — long paths use GNU long-name entries.
- **Flat memory** — reading and writing are streamed in 1 MiB chunks, so archive
  and file size do not affect memory usage.

The archives are standard and readable by GNU `tar` / `bsdtar`.

## Files-only backups

```php
'profiles' => [
    'uploads' => [
        'type' => 'files',
        'files' => ['include' => [storage_path('app/public')]],
        'destinations' => ['s3'],
    ],
],
```
