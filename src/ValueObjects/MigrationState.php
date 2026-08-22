<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\ValueObjects;

/**
 * Which migrations had run when a backup was taken.
 *
 * A database dump only fits a codebase whose schema expectations match it, and
 * the app version alone does not establish that — two commits can share a
 * version string and differ by a migration. Recording the ran migrations in the
 * manifest makes the fit checkable from the sidecar, without decrypting or
 * unpacking the archive, and makes two backups diffable against each other.
 *
 * The dump itself still contains the `migrations` table; this is the same fact
 * lifted somewhere it can be read from the outside.
 */
final readonly class MigrationState
{
    /**
     * @param  list<string>  $ran  Migration names in the order they were applied.
     */
    public function __construct(
        public array $ran = [],
    ) {}

    public function count(): int
    {
        return count($this->ran);
    }

    /**
     * The most recently applied migration, or null when none has run.
     */
    public function latest(): ?string
    {
        return $this->ran === [] ? null : $this->ran[array_key_last($this->ran)];
    }

    /**
     * Migrations this state has that $other does not.
     *
     * Directional on purpose: `$backup->ahead($other)` and
     * `$other->ahead($backup)` answer different questions, and restoring across
     * a gap is only safe in one of them.
     *
     * @return list<string>
     */
    public function ahead(self $other): array
    {
        return array_values(array_diff($this->ran, $other->ran));
    }

    public function matches(self $other): bool
    {
        return $this->ran === $other->ran;
    }

    /**
     * @return array{ran: list<string>}
     */
    public function toArray(): array
    {
        return ['ran' => $this->ran];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $ran */
        $ran = array_values(array_filter(
            (array) ($data['ran'] ?? []),
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));

        return new self($ran);
    }
}
