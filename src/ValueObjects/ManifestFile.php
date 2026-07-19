<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * A single entry in a backup manifest describing one archived file.
 *
 * Records the path as stored inside the archive, its byte size and a
 * checksum so that individual files can be verified after restore.
 */
final readonly class ManifestFile
{
    public function __construct(
        public string $path,
        public int $size,
        public Checksum $checksum,
        public bool $isSymlink = false,
        public ?string $symlinkTarget = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            path: $data['path'],
            size: $data['size'],
            checksum: Checksum::fromArray($data['checksum']),
            isSymlink: $data['is_symlink'] ?? false,
            symlinkTarget: $data['symlink_target'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'size' => $this->size,
            'checksum' => $this->checksum->toArray(),
            'is_symlink' => $this->isSymlink,
            'symlink_target' => $this->symlinkTarget,
        ];
    }
}
