<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use DateTimeImmutable;
use Nyoncode\BackupManager\DTOs\BackupDefinition;
use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\ValueObjects\DatabaseArtifact;
use Nyoncode\BackupManager\ValueObjects\EnvironmentInfo;
use Nyoncode\BackupManager\ValueObjects\Manifest;
use Nyoncode\BackupManager\ValueObjects\ManifestFile;
use Nyoncode\BackupManager\ValueObjects\SnapshotDiff;

/**
 * The mutable payload threaded through the backup pipeline.
 *
 * This is the one deliberately mutable object in the package: each stage
 * enriches it (dumps, files, archive paths, manifest) before handing it on.
 * Keeping the mutation surface in a single, well-documented value keeps the
 * stages themselves small and free of shared state.
 */
final class BackupContext
{
    /** @var array<string, string> Archive path => absolute source path. */
    public array $fileEntries = [];

    /** @var list<ManifestFile> */
    public array $manifestFiles = [];

    /** @var list<DatabaseArtifact> */
    public array $databaseArtifacts = [];

    public string $workspace = '';

    public string $tarPath = '';

    public string $compressedPath = '';

    public string $archivePath = '';

    public string $archiveFileName = '';

    public ?Manifest $manifest = null;

    /** Populated for incremental runs: what changed against the previous snapshot. */
    public ?SnapshotDiff $snapshotDiff = null;

    public function __construct(
        public readonly BackupDefinition $definition,
        public readonly EnvironmentInfo $environment,
        public readonly CompressionAlgorithm $compression,
        public readonly EncryptionAlgorithm $encryption,
        public readonly ChecksumAlgorithm $checksum,
        public readonly DateTimeImmutable $startedAt,
        public readonly string $baseName,
    ) {}

    public function manifestFileName(): string
    {
        return "{$this->baseName}.manifest.json";
    }
}
