<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

use DateTimeImmutable;
use JsonException;
use Nyoncode\BackupManager\Enums\BackupMode;
use Nyoncode\BackupManager\Enums\BackupType;
use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Enums\CompressionAlgorithm;
use Nyoncode\BackupManager\Enums\EncryptionAlgorithm;
use Nyoncode\BackupManager\Exceptions\ManifestException;

/**
 * The backup manifest — a self-describing, machine-readable record of a backup.
 *
 * Every backup ships with a manifest capturing the environment it was taken
 * from, the drivers used, the archive checksum and a full listing of every
 * file with its individual hash. This is what makes a backup verifiable and
 * portable independently of the tooling that produced it.
 *
 * The manifest never contains secrets (encryption keys, passwords); it only
 * records which algorithms were applied.
 *
 * @phpstan-type ManifestArray array<string, mixed>
 */
final readonly class Manifest
{
    /**
     * The manifest schema version. Bump on breaking structural changes.
     *
     * 1.1 adds the storage `mode` (archive vs incremental snapshot); older
     * manifests without it are read back as `archive`.
     */
    public const SCHEMA_VERSION = '1.1';

    /**
     * @param  list<DatabaseArtifact>  $databases
     * @param  list<ManifestFile>  $files
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $name,
        public BackupType $type,
        public DateTimeImmutable $createdAt,
        public EnvironmentInfo $environment,
        public CompressionAlgorithm $compression,
        public EncryptionAlgorithm $encryption,
        public array $databases,
        public array $files,
        public int $archiveSize,
        public Checksum $archiveChecksum,
        public array $config = [],
        public BackupMode $mode = BackupMode::Archive,
        public ?Checksum $signature = null,
        public string $schemaVersion = self::SCHEMA_VERSION,
    ) {}

    /**
     * A stable integrity anchor over a snapshot's file listing.
     *
     * Hashes the canonical `path\0algorithm:digest` line for every file (sorted
     * by path) so it depends only on *what the snapshot contains*, not on JSON
     * formatting or ordering. For incremental snapshots — which have no single
     * archive — this stands in for the archive checksum, letting a restore
     * confirm the reassembled set of blobs matches what was recorded.
     *
     * @param  list<ManifestFile>  $files
     */
    public static function indexChecksum(array $files, ChecksumAlgorithm $algorithm): Checksum
    {
        $lines = array_map(
            static fn (ManifestFile $file): string => $file->path."\0".$file->checksum,
            $files,
        );

        sort($lines);

        return Checksum::forContents(implode("\n", $lines), $algorithm);
    }

    /**
     * Total number of archived files.
     */
    public function fileCount(): int
    {
        return count($this->files);
    }

    /**
     * Total uncompressed byte size of all archived files.
     */
    public function totalFileSize(): int
    {
        return array_sum(array_map(static fn (ManifestFile $file): int => $file->size, $this->files));
    }

    public function isEncrypted(): bool
    {
        return $this->encryption->isEnabled();
    }

    public function isSigned(): bool
    {
        return $this->signature !== null;
    }

    /**
     * Return a copy carrying the given signature.
     */
    public function withSignature(Checksum $signature): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            createdAt: $this->createdAt,
            environment: $this->environment,
            compression: $this->compression,
            encryption: $this->encryption,
            databases: $this->databases,
            files: $this->files,
            archiveSize: $this->archiveSize,
            archiveChecksum: $this->archiveChecksum,
            config: $this->config,
            mode: $this->mode,
            signature: $signature,
            schemaVersion: $this->schemaVersion,
        );
    }

    /**
     * Return a copy with any signature removed.
     *
     * Used during verification to reconstruct the exact payload that was
     * originally signed (the manifest before its signature was attached).
     */
    public function withoutSignature(): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            createdAt: $this->createdAt,
            environment: $this->environment,
            compression: $this->compression,
            encryption: $this->encryption,
            databases: $this->databases,
            files: $this->files,
            archiveSize: $this->archiveSize,
            archiveChecksum: $this->archiveChecksum,
            config: $this->config,
            mode: $this->mode,
            signature: null,
            schemaVersion: $this->schemaVersion,
        );
    }

    /**
     * @return ManifestArray
     */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'name' => $this->name,
            'type' => $this->type->value,
            'mode' => $this->mode->value,
            'created_at' => $this->createdAt->format(DateTimeImmutable::ATOM),
            'environment' => $this->environment->toArray(),
            'drivers' => [
                'compression' => $this->compression->value,
                'encryption' => $this->encryption->value,
                'checksum' => $this->archiveChecksum->algorithm->value,
            ],
            'encryption' => [
                'enabled' => $this->isEncrypted(),
                'algorithm' => $this->encryption->value,
            ],
            'databases' => array_map(
                static fn (DatabaseArtifact $artifact): array => $artifact->toArray(),
                $this->databases,
            ),
            'archive' => [
                'size' => $this->archiveSize,
                'checksum' => $this->archiveChecksum->toArray(),
            ],
            'files' => array_map(
                static fn (ManifestFile $file): array => $file->toArray(),
                $this->files,
            ),
            'config' => $this->config,
            'signature' => $this->signature?->toArray(),
        ];
    }

    /**
     * Serialize the manifest to pretty-printed JSON.
     *
     * @throws ManifestException
     */
    public function toJson(): string
    {
        try {
            return json_encode(
                $this->toArray(),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
        } catch (JsonException $exception) {
            throw ManifestException::invalidJson($exception->getMessage(), $exception);
        }
    }

    /**
     * Rebuild a manifest from its JSON representation.
     *
     * @throws ManifestException
     */
    public static function fromJson(string $json): self
    {
        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw ManifestException::invalidJson($exception->getMessage(), $exception);
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ManifestException
     */
    public static function fromArray(array $data): self
    {
        foreach (['name', 'type', 'created_at', 'environment', 'archive'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw ManifestException::missingKey($key);
            }
        }

        return new self(
            name: $data['name'],
            type: BackupType::from($data['type']),
            createdAt: new DateTimeImmutable($data['created_at']),
            environment: EnvironmentInfo::fromArray($data['environment']),
            compression: CompressionAlgorithm::from($data['drivers']['compression'] ?? 'none'),
            encryption: EncryptionAlgorithm::from($data['drivers']['encryption'] ?? 'none'),
            databases: array_values(array_map(
                static fn (array $artifact): DatabaseArtifact => DatabaseArtifact::fromArray($artifact),
                $data['databases'] ?? [],
            )),
            files: array_values(array_map(
                static fn (array $file): ManifestFile => ManifestFile::fromArray($file),
                $data['files'] ?? [],
            )),
            archiveSize: $data['archive']['size'],
            archiveChecksum: Checksum::fromArray($data['archive']['checksum']),
            config: $data['config'] ?? [],
            mode: BackupMode::from($data['mode'] ?? 'archive'),
            signature: isset($data['signature']) ? Checksum::fromArray($data['signature']) : null,
            schemaVersion: $data['schema_version'] ?? self::SCHEMA_VERSION,
        );
    }
}
