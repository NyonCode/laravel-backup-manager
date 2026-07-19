<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use FilesystemIterator;
use Nyoncode\BackupManager\Exceptions\BackupManagerException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A disposable temporary directory with a recursive teardown.
 */
final readonly class Workspace
{
    private function __construct(
        public string $root,
    ) {}

    public static function create(string $base, string $prefix): self
    {
        $root = rtrim($base, '/').'/'.$prefix.'-'.bin2hex(random_bytes(6));

        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new BackupManagerException("Unable to create working directory [{$root}].");
        }

        return new self($root);
    }

    public function path(string $relative = ''): string
    {
        return $relative === '' ? $this->root : rtrim($this->root, '/').'/'.$relative;
    }

    public function delete(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->root);
    }
}
