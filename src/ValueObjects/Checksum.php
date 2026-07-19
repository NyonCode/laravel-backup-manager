<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

use Nyoncode\BackupManager\Enums\ChecksumAlgorithm;
use Nyoncode\BackupManager\Exceptions\IntegrityException;

/**
 * An immutable integrity checksum: an algorithm paired with its hex digest.
 *
 * Comparison is constant-time to avoid leaking timing information when
 * verifying signatures or archive integrity.
 */
final readonly class Checksum
{
    public function __construct(
        public ChecksumAlgorithm $algorithm,
        public string $digest,
    ) {}

    /**
     * Compute the checksum of a file on disk without loading it into memory.
     */
    public static function forFile(string $path, ChecksumAlgorithm $algorithm = ChecksumAlgorithm::Sha256): self
    {
        $digest = hash_file($algorithm->hashName(), $path);

        if ($digest === false) {
            throw IntegrityException::checksumMismatch($path, 'computable hash', 'unreadable file');
        }

        return new self($algorithm, $digest);
    }

    /**
     * Compute the checksum of an in-memory string.
     */
    public static function forContents(string $contents, ChecksumAlgorithm $algorithm = ChecksumAlgorithm::Sha256): self
    {
        return new self($algorithm, hash($algorithm->hashName(), $contents));
    }

    /**
     * Reconstruct a checksum from its stored representation.
     *
     * @param  array{algorithm: string, digest: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            ChecksumAlgorithm::from($data['algorithm']),
            $data['digest'],
        );
    }

    /**
     * Constant-time equality against another checksum of the same algorithm.
     */
    public function equals(self $other): bool
    {
        return $this->algorithm === $other->algorithm
            && hash_equals($this->digest, $other->digest);
    }

    /**
     * Verify that a file on disk matches this checksum.
     */
    public function matchesFile(string $path): bool
    {
        return $this->equals(self::forFile($path, $this->algorithm));
    }

    /**
     * @return array{algorithm: string, digest: string}
     */
    public function toArray(): array
    {
        return [
            'algorithm' => $this->algorithm->value,
            'digest' => $this->digest,
        ];
    }

    public function __toString(): string
    {
        return "{$this->algorithm->value}:{$this->digest}";
    }
}
