# Contributing

Thanks for your interest in improving Backup Manager! This document explains how
to set up the project, the checks your change must pass, and the conventions the
codebase follows.

By participating you agree to keep the tone respectful and constructive.

---

## Getting started

```bash
git clone https://github.com/nyoncode/laravel-backup-manager.git
cd laravel-backup-manager
composer install
```

Requirements: **PHP 8.3+** and the `openssl` / `json` extensions. `ext-bz2` and
the `zstd` binary are optional (only the matching compression tests need them).

---

## Running the checks

Everything CI enforces can be run locally:

```bash
composer test        # Unit + Feature suites (no database server needed)
composer analyse     # PHPStan, level 8
composer lint        # Pint code-style check (read-only)
composer format      # Pint, apply fixes
```

A change is ready when `composer test`, `composer analyse` and `composer lint`
all pass.

### Integration tests (real databases)

The integration suite runs real backup → restore round-trips against live MySQL,
MariaDB, PostgreSQL and MinIO (S3). Point it at your own servers with `BM_*`
environment variables — see `tests/Integration/DatabaseTestKit.php` for the full
list of keys and their defaults. Any service that isn't reachable is **skipped
automatically**, so `composer test` stays green without them.

```bash
# Example: run only the PostgreSQL leg against a local server
BM_PGSQL_HOST=127.0.0.1 BM_PGSQL_PORT=5432 BM_PGSQL_USERNAME=postgres \
BM_PGSQL_PASSWORD=postgres BM_PGSQL_DATABASE=backup_manager_test \
composer test:integration -- --group=pgsql
```

Groups: `mysql`, `mariadb`, `pgsql`, `s3`. Run them all with
`composer test:integration`.

> **PostgreSQL:** use a `pg_dump` / `psql` whose version is **≤ the server
> version**; a newer client emits settings an older server rejects on restore.
> Point `backup-manager.binaries.pg_dump` / `psql` at a matching client if needed.

---

## Coding conventions

The package is built around **composition over inheritance**. Please keep to the
existing style:

- **Small interfaces, focused implementations.** A capability is a contract
  (`DatabaseDumper`, `Compressor`, `Encryptor`, `Signer`, `Archiver`,
  `BackupRepository`, `RetentionStrategy`, `BackupStage`) with a single, cohesive
  implementation.
- **`final` classes** by default; make something extendable only with a reason.
- **Immutable value objects and DTOs**; no business logic in them.
- **Dependency injection** — never `new` a service inside another; inject it.
- **Business logic lives in Actions / Managers / Services**, not in traits.
- Strict types (`declare(strict_types=1);`) and full type hints; PHPStan level 8
  must stay green.
- Match the surrounding code's naming, comments and idioms. Pint (Laravel preset)
  is the source of truth for formatting.

Directory layout mirrors these roles: `Contracts/`, `Actions/`, `Services/`,
`Managers/`, `Support/`, `Enums/`, `Exceptions/`, `DTOs/`, `ValueObjects/`,
`Events/`, `Listeners/`, `Commands/`.

### Adding a capability

Most features are **additive** — a new implementation of an existing contract or
a new pipeline stage — rather than a change to the core:

- A **database engine**: implement `DatabaseDumper` + `DatabaseRestorer` and
  register them in `DatabaseManager`.
- A **compression / encryption** algorithm: implement `Compressor` / `Encryptor`
  and add a case to the matching manager.
- A **pipeline step**: implement `BackupStage` and add it to the stage list in the
  service provider.

See [docs/extending.md](docs/extending.md) for details.

---

## Submitting a change

1. **Open an issue first** for anything non-trivial, describing the use case (not
   just the proposed feature). Because the core is interface-driven, a proof of
   concept is usually one small class.
2. Branch from `main`.
3. Add or update **tests** for your change, and an entry under `## [Unreleased]`
   in [CHANGELOG.md](CHANGELOG.md).
4. Make sure `composer test`, `composer analyse` and `composer lint` pass.
5. Open a pull request with a clear description of **what** changed and **why**.

Keep pull requests focused — one logical change per PR is much easier to review.

---

## Security

Please **do not** open public issues for security vulnerabilities. Report them
privately to the maintainer instead; you'll get a response as quickly as
possible.

---

## Ideas & roadmap

Curious where the project is heading, or looking for something to work on? See
[ROADMAP.md](ROADMAP.md).
