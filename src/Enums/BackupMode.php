<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Enums;

/**
 * How a backup is stored — orthogonal to its {@see BackupType} scope.
 *
 * `Archive` produces a single self-contained tar (optionally compressed and
 * encrypted): the reliable, standalone anchor. `Incremental` writes a snapshot
 * whose files live in a shared, content-addressed blob pool, so only content
 * that changed since the previous snapshot is uploaded.
 *
 * A profile of any scope can use either mode: a full nightly archive and a
 * frequent incremental snapshot are complementary, not alternatives.
 */
enum BackupMode: string
{
    case Archive = 'archive';
    case Incremental = 'incremental';

    public function isIncremental(): bool
    {
        return $this === self::Incremental;
    }

    public function label(): string
    {
        return match ($this) {
            self::Archive => 'Self-contained archive',
            self::Incremental => 'Incremental snapshot',
        };
    }
}
