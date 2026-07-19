<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

use Nyoncode\BackupManager\Support\BackupContext;

/**
 * The outcome of a successful backup run.
 */
final readonly class BackupResult
{
    /**
     * @param  list<string>  $destinations
     * @param  SnapshotDiff|null  $snapshotDiff  For incremental runs: what changed since the previous snapshot.
     */
    public function __construct(
        public string $profile,
        public string $archiveFileName,
        public int $archiveSize,
        public Manifest $manifest,
        public array $destinations,
        public ?SnapshotDiff $snapshotDiff = null,
    ) {}

    public static function fromContext(BackupContext $context): self
    {
        $manifest = $context->manifest
            ?? throw new \LogicException('Backup finished without a manifest.');

        return new self(
            profile: $context->definition->profile,
            archiveFileName: $context->archiveFileName,
            archiveSize: $manifest->archiveSize,
            manifest: $manifest,
            destinations: $context->definition->destinations,
            snapshotDiff: $context->snapshotDiff,
        );
    }

    public function isIncremental(): bool
    {
        return $this->manifest->mode->isIncremental();
    }
}
