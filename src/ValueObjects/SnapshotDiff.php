<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * The difference between the current source tree and a previous snapshot.
 *
 * Every backup already records a checksum for each file, so the previous
 * snapshot's manifest is exactly the index needed to classify the current
 * tree without touching a byte of the earlier backup: a file is *added* when
 * its path is new, *changed* when the path exists but its content hash moved,
 * *unchanged* when both path and hash match, and *removed* when a path from
 * the base is gone.
 *
 * The union of added and changed files is what an incremental backup must
 * actually store; the unchanged remainder is already deduplicated in the blob
 * pool and costs nothing to carry forward.
 */
final readonly class SnapshotDiff
{
    /**
     * @param  list<ManifestFile>  $added  Files whose path did not exist in the base.
     * @param  list<ManifestFile>  $changed  Files whose content hash differs from the base.
     * @param  list<ManifestFile>  $unchanged  Files identical to the base (path and hash).
     * @param  list<ManifestFile>  $removed  Base files with no counterpart in the current tree.
     */
    public function __construct(
        public array $added,
        public array $changed,
        public array $unchanged,
        public array $removed,
    ) {}

    /**
     * Diff a freshly collected tree against a previous snapshot's file list.
     *
     * Both arguments are manifest file listings; pass `$manifest?->files ?? []`
     * for the base so the very first backup (no predecessor) treats every file
     * as added.
     *
     * @param  list<ManifestFile>  $base  The previous snapshot's files.
     * @param  list<ManifestFile>  $current  The freshly collected files.
     */
    public static function between(array $base, array $current): self
    {
        $baseByPath = self::keyByPath($base);
        $currentByPath = self::keyByPath($current);

        $added = [];
        $changed = [];
        $unchanged = [];

        foreach ($current as $file) {
            $previous = $baseByPath[$file->path] ?? null;

            if ($previous === null) {
                $added[] = $file;
            } elseif ($previous->checksum->equals($file->checksum)) {
                $unchanged[] = $file;
            } else {
                $changed[] = $file;
            }
        }

        $removed = [];

        foreach ($base as $file) {
            if (! isset($currentByPath[$file->path])) {
                $removed[] = $file;
            }
        }

        return new self($added, $changed, $unchanged, $removed);
    }

    /**
     * The files an incremental backup must store: everything added or changed.
     *
     * @return list<ManifestFile>
     */
    public function newFiles(): array
    {
        return array_merge($this->added, $this->changed);
    }

    /**
     * Total uncompressed byte size of the added and changed files.
     */
    public function newByteSize(): int
    {
        return array_sum(array_map(
            static fn (ManifestFile $file): int => $file->size,
            $this->newFiles(),
        ));
    }

    /**
     * Whether nothing changed since the base snapshot.
     */
    public function isUnchanged(): bool
    {
        return $this->added === [] && $this->changed === [] && $this->removed === [];
    }

    /**
     * @return array<string, int>
     */
    public function counts(): array
    {
        return [
            'added' => count($this->added),
            'changed' => count($this->changed),
            'unchanged' => count($this->unchanged),
            'removed' => count($this->removed),
        ];
    }

    /**
     * @param  list<ManifestFile>  $files
     * @return array<string, ManifestFile>
     */
    private static function keyByPath(array $files): array
    {
        $keyed = [];

        foreach ($files as $file) {
            $keyed[$file->path] = $file;
        }

        return $keyed;
    }
}
