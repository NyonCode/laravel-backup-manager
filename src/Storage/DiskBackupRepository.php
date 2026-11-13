<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Storage;

use DateTimeImmutable;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Nyoncode\BackupManager\Contracts\Storage\BackupRepository;
use Nyoncode\BackupManager\Enums\BackupMode;
use Nyoncode\BackupManager\Exceptions\ManifestException;
use Nyoncode\BackupManager\Exceptions\StorageException;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\StoredBackup;
use Throwable;

/**
 * Reads and manages backups stored on any Laravel filesystem disk.
 */
final class DiskBackupRepository implements BackupRepository
{
    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {}

    public function list(string $disk, string $path): array
    {
        $storage = $this->filesystem->disk($disk);
        $directory = trim($path, '/');
        $backups = [];

        foreach ($storage->files($directory) as $file) {
            $name = basename($file);

            if (str_ends_with($file, '.manifest.json') || ! StoredBackup::isArchiveName($name)) {
                continue;
            }
            $manifestPath = $directory.'/'.StoredBackup::manifestNameFor($name);

            $backups[] = new StoredBackup(
                disk: $disk,
                archivePath: $file,
                manifestPath: $manifestPath,
                name: $name,
                size: (int) $storage->size($file),
                createdAt: $this->createdAt($name, (int) $storage->lastModified($file)),
                profile: $this->profileOf($storage, $manifestPath),
            );
        }

        usort($backups, static fn (StoredBackup $a, StoredBackup $b): int => $b->createdAt <=> $a->createdAt);

        return $backups;
    }

    public function listSnapshots(string $disk, string $path): array
    {
        $storage = $this->filesystem->disk($disk);
        $directory = trim($path, '/').'/snapshots';
        $snapshots = [];

        foreach ($storage->files($directory) as $file) {
            if (! str_ends_with($file, '.manifest.json')) {
                continue;
            }

            try {
                $manifest = Manifest::fromJson((string) $storage->get($file));
            } catch (Throwable) {
                // A manifest we cannot parse is not a snapshot we can offer.
                continue;
            }

            $profile = $manifest->config['profile'] ?? null;

            $snapshots[] = new StoredBackup(
                disk: $disk,
                archivePath: '',
                manifestPath: $file,
                name: basename($file),
                size: $manifest->archiveSize,
                createdAt: $manifest->createdAt,
                mode: BackupMode::Incremental,
                profile: is_string($profile) && $profile !== '' ? $profile : null,
            );
        }

        usort($snapshots, static fn (StoredBackup $a, StoredBackup $b): int => $b->createdAt <=> $a->createdAt);

        return $snapshots;
    }

    public function readManifest(StoredBackup $backup): Manifest
    {
        $storage = $this->filesystem->disk($backup->disk);

        if (! $storage->exists($backup->manifestPath)) {
            throw ManifestException::notFound($backup->manifestPath);
        }

        return Manifest::fromJson((string) $storage->get($backup->manifestPath));
    }

    public function download(StoredBackup $backup, string $localPath): void
    {
        $storage = $this->filesystem->disk($backup->disk);

        try {
            $source = $storage->readStream($backup->archivePath);
            $target = fopen($localPath, 'wb');

            if (! is_resource($source) || $target === false) {
                throw StorageException::readFailed($backup->disk, $backup->archivePath);
            }

            stream_copy_to_stream($source, $target);

            fclose($target);
            fclose($source);
        } catch (Throwable $exception) {
            throw StorageException::readFailed($backup->disk, $backup->archivePath, $exception);
        }
    }

    public function delete(StoredBackup $backup): void
    {
        $storage = $this->filesystem->disk($backup->disk);

        // A snapshot has no standalone archive — only its manifest is removed
        // here; the blobs it referenced are reclaimed by garbage collection,
        // never deleted outright (other snapshots may still share them).
        if ($backup->archivePath !== '') {
            $storage->delete($backup->archivePath);
        }

        if ($storage->exists($backup->manifestPath)) {
            $storage->delete($backup->manifestPath);
        }
    }

    /**
     * The profile an archive was taken under, from its manifest sidecar.
     *
     * Reads only the one key rather than hydrating a whole {@see Manifest}: this
     * runs once per archive in a listing, and a sidecar written by a newer
     * schema — or none at all — must not cost the listing. Anything unreadable
     * answers null, which every caller reads as "unknown, treat as ours".
     */
    private function profileOf(Filesystem $storage, string $manifestPath): ?string
    {
        try {
            $raw = $storage->get($manifestPath);

            if (! is_string($raw)) {
                return null;
            }

            /** @var array<string, mixed>|null $data */
            $data = json_decode($raw, true);
            $profile = is_array($data) && is_array($data['config'] ?? null)
                ? ($data['config']['profile'] ?? null)
                : null;

            return is_string($profile) && $profile !== '' ? $profile : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function createdAt(string $name, int $fallbackTimestamp): DateTimeImmutable
    {
        if (preg_match('/-(\d{4})-(\d{2})-(\d{2})-(\d{2})(\d{2})(\d{2})/', $name, $m) === 1) {
            $parsed = DateTimeImmutable::createFromFormat(
                'Y-m-d-His',
                "{$m[1]}-{$m[2]}-{$m[3]}-{$m[4]}{$m[5]}{$m[6]}",
            );

            if ($parsed !== false) {
                return $parsed;
            }
        }

        return (new DateTimeImmutable)->setTimestamp($fallbackTimestamp);
    }
}
