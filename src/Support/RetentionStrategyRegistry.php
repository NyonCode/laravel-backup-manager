<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Support;

use Closure;
use Nyoncode\BackupManager\Contracts\Retention\RetentionStrategy;
use Nyoncode\BackupManager\DTOs\RetentionPolicy;

/**
 * A registry of named retention strategies.
 *
 * The strategy a policy names (`'simple'`, `'gfs'`, …) is resolved here rather
 * than hard-wired, so an add-on can contribute a strategy without touching the
 * selector. The free core registers only `simple`; the Pro package registers
 * `gfs`. A name with no registered factory resolves to null, and the selector
 * treats that as "keep everything" — retention fails **closed**, never deleting
 * a backup under a strategy it cannot honour.
 */
final class RetentionStrategyRegistry
{
    /**
     * @var array<string, Closure(RetentionPolicy): RetentionStrategy>
     */
    private array $factories = [];

    /**
     * @param  Closure(RetentionPolicy): RetentionStrategy  $factory
     */
    public function register(string $name, Closure $factory): void
    {
        $this->factories[$name] = $factory;
    }

    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * Build the strategy a policy names, or null when it is not registered.
     */
    public function make(string $name, RetentionPolicy $policy): ?RetentionStrategy
    {
        $factory = $this->factories[$name] ?? null;

        return $factory === null ? null : $factory($policy);
    }
}
