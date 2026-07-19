<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Actions\Files;

use Nyoncode\BackupManager\DTOs\FileSource;
use Nyoncode\BackupManager\Support\ArchivePath;
use Nyoncode\BackupManager\Support\PathMatcher;
use Nyoncode\BackupManager\ValueObjects\CollectedFile;
use SplFileInfo;
use Symfony\Component\Finder\Finder;

/**
 * Walks the configured source roots and produces the list of files to archive.
 *
 * Applies include/exclude filters, honours the hidden-file and symlink
 * settings, and normalises every entry to an unambiguous archive path.
 * Symlinks that are not followed are recorded as link metadata (no content)
 * so they can be faithfully recreated on restore.
 */
final class CollectFiles
{
    public function __construct(
        private readonly PathMatcher $matcher,
    ) {}

    /**
     * @return list<CollectedFile>
     */
    public function execute(FileSource $source): array
    {
        $collected = [];

        foreach ($source->includePaths as $root) {
            if (is_file($root)) {
                if (! $this->matcher->isExcluded($root, $source->excludePatterns)) {
                    $collected[] = $this->makeFile($root, $source->followSymlinks);
                }

                continue;
            }

            if (! is_dir($root)) {
                continue;
            }

            foreach ($this->finder($source, $root) as $file) {
                $collected[] = $this->makeFile($file->getPathname(), $source->followSymlinks);
            }
        }

        return $collected;
    }

    private function finder(FileSource $source, string $root): Finder
    {
        $finder = Finder::create()
            ->files()
            ->in($root)
            ->ignoreDotFiles(! $source->includeHidden)
            ->ignoreVCS(false)
            ->filter(fn (SplFileInfo $file): bool => ! $this->matcher->isExcluded(
                $file->getPathname(),
                $source->excludePatterns,
            ));

        if ($source->followSymlinks) {
            $finder->followLinks();
        }

        return $finder;
    }

    private function makeFile(string $absolutePath, bool $followSymlinks): CollectedFile
    {
        $isUnfollowedSymlink = ! $followSymlinks && is_link($absolutePath);

        return new CollectedFile(
            absolutePath: $absolutePath,
            archivePath: $this->archivePath($absolutePath),
            size: $isUnfollowedSymlink ? 0 : (int) (filesize($absolutePath) ?: 0),
            isSymlink: $isUnfollowedSymlink,
            symlinkTarget: $isUnfollowedSymlink ? (readlink($absolutePath) ?: null) : null,
        );
    }

    /**
     * Mirror the absolute path inside the archive under a `files/` prefix.
     */
    private function archivePath(string $absolutePath): string
    {
        return ArchivePath::fromAbsolute($absolutePath);
    }
}
