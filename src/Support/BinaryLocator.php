<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Nyoncode\BackupManager\Exceptions\DatabaseDumpException;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Resolves absolute paths to external command line tools.
 *
 * Explicit paths from configuration take precedence; otherwise the binary is
 * discovered on the system PATH. Resolved paths are memoised.
 */
final class BinaryLocator
{
    /** @var array<string, string> */
    private array $resolved = [];

    /**
     * @param  array<string, string|null>  $configuredPaths  Map of binary name to configured path.
     */
    public function __construct(
        private readonly array $configuredPaths = [],
        private readonly ExecutableFinder $finder = new ExecutableFinder,
    ) {}

    /**
     * Locate a binary, throwing when it cannot be found.
     *
     * @throws DatabaseDumpException
     */
    public function locate(string $binary): string
    {
        return $this->resolved[$binary] ??= $this->resolve($binary)
            ?? throw DatabaseDumpException::binaryNotFound($binary);
    }

    /**
     * Determine whether a binary is available without throwing.
     */
    public function has(string $binary): bool
    {
        return $this->resolve($binary) !== null;
    }

    private function resolve(string $binary): ?string
    {
        $configured = $this->configuredPaths[$binary] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->finder->find($binary) ?: null;
    }
}
