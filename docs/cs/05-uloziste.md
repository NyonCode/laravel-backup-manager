---
title: Úložiště (cíle)
description: "filesystem, funguje jakýkoli ovladač disku bez zvláštního kódu: Local, S3-kompatibilní object storage, FTP, SFTP, Azure Blob, Backblaze B2, WebDAV a cokoli…"
key: storage
---
# Úložiště (cíle)

**Cíl** je běžný Laravel filesystem disk. Protože balíček zapisuje přes Laravel
filesystem, funguje **jakýkoli** ovladač disku bez zvláštního kódu: Local,
S3-kompatibilní object storage, FTP, SFTP, Azure Blob, Backblaze B2, WebDAV a
cokoli dalšího s Flysystem adaptérem.

## Výběr cílů

```php
'profiles' => [
    'nightly' => [
        'destinations' => ['s3', 'local'],  // zapíše se na OBA
        'path' => 'backups',                // podadresář na každém disku
    ],
],
```

Záloha se streamuje na **každý** uvedený disk. Archiv se nahrává streamem, takže
velké zálohy se nemusí vejít do paměti.

## Příklady disků

Definujte je v `config/filesystems.php` jako obvykle a poté je odkazujte názvem.

### S3 / S3-kompatibilní (MinIO, DigitalOcean Spaces, …)

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'endpoint' => env('AWS_ENDPOINT'),  // pro S3-kompatibilní poskytovatele
],
```

Vyžaduje `composer require league/flysystem-aws-s3-v3`.

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

Vyžaduje `composer require league/flysystem-sftp-v3`.

### Backblaze B2 / WebDAV / Azure Blob

Nainstalujte odpovídající Flysystem adaptér a zaregistrujte disk v
`config/filesystems.php`. V Backup Manageru není potřeba nic měnit — použije se
jen název disku.

## Co se ukládá

Pro každou zálohu se pod `path` zapíšou dva objekty:

```text
backups/nightly-2026-07-16-020000.tar.gz.enc     ← archiv
backups/nightly-2026-07-16-020000.manifest.json  ← přiložený manifest
```

Manifest se ukládá **nešifrovaně** vedle archivu, takže jej lze prohlédnout a
ověřit kontrolní součet archivu bez stažení či dešifrování archivu. Nikdy
neobsahuje tajemství — pouze použité algoritmy.

## Výpis a mazání

`php artisan backup:list` čte sidecary a vypisuje uložené zálohy,
`php artisan backup:clean` maže archivy (a jejich sidecary) dle
[retenční politiky](08-retence.md). Oba pracují přes rozhraní `BackupRepository`,
takže fungují identicky na každém disku.
