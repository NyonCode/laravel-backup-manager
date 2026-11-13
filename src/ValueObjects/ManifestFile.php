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
        /** Permission bits (0777 mask) when recorded; null for older manifests and links. */
        public ?int $mode = null,
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
            mode: isset($data['mode']) && is_int($data['mode']) ? $data['mode'] & 0777 : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'path' => $this->path,
            'size' => $this->size,
            'checksum' => $this->checksum->toArray(),
            'is_symlink' => $this->isSymlink,
            'symlink_target' => $this->symlinkTarget,
        ];

        // Only when known. A manifest is verified by re-serialising it, so a key
        // that older manifests never had must not appear in them as null — it
        // would change the bytes their signature was computed over.
        if ($this->mode !== null) {
            $data['mode'] = $this->mode;
        }

        return $data;
    }
}
