<?php

declare(strict_types=1);

namespace Nyoncode\BackupManager\Mcp\Support;

use Illuminate\Contracts\Config\Repository;
use Nyoncode\BackupManager\Support\Edition;

/**
 * The playbook an assistant follows to set backups up end to end.
 *
 * It exists because the documentation does not ship with the Composer package
 * (`docs/` is export-ignored): this is the only guidance an installed copy can
 * hand to a model, so it carries the order of operations *and* the rules that
 * keep a backup restorable.
 */
final class SetupGuide
{
    public function __construct(private readonly Repository $config) {}

    public function render(): string
    {
        $profiles = implode(', ', array_map('strval', array_keys((array) $this->config->get('backup-manager.profiles', []))));
        $edition = Edition::proInstalled() ? 'core + Pro' : 'free core';

        return <<<GUIDE
        # Setting up backups with Backup Manager

        Installed: {$edition}. Profiles right now: {$profiles}.

        ## Order of work

        1. `backup_overview` — what edition is installed, what is configured, what is already broken.
        2. `backup_inspect_environment` — the disks, database connections and dump binaries this host
           actually has. Never propose a destination disk or a connection that is not in that list.
        3. `backup_config_reference` — every key you may set, with its type, allowed values and
           environment variable. Do not invent keys.
        4. `backup_configure` with `dry_run: true` — it returns the exact changes *and* the findings the
           configuration would then have. Read the findings before applying.
        5. `backup_configure` with `dry_run: false` — applies to `.env` / `config/backup-manager.php`.
        6. `backup_run`, then `backup_verify` — a configuration that has never produced a verified
           backup is a guess.

        ## Decisions worth getting right

        - **Scope.** `type: full` (database + files) is the safe default. Files-heavy applications where
          user uploads live on S3 are often better served by `type: database` plus a separate profile.
        - **Destination.** At least one off-site disk. A backup on the same machine as the data it
          protects does not survive the failure that matters.
        - **Encryption.** Set `encryption.algorithm` to `aes-256-cbc` (streamed; any size) and put a
          32-byte key in `.env` as `BACKUP_ENCRYPTION_KEY`, `base64:` prefixed. `aes-256-gcm` is
          authenticated but buffers the whole archive in memory. Tell the user, in plain words, to store
          a copy of the key somewhere other than the server: without it no backup can ever be restored.
        - **Retention.** `simple` keeps the newest N. For nightly backups, keep at least 7. GFS
          (daily/weekly/monthly/yearly) needs Pro; selecting it without Pro prunes nothing.
        - **Schedule.** `schedule.enabled: true` plus cron expressions registers `backup:run` and
          `backup:clean` with Laravel's scheduler — which only runs if the *system* cron calls
          `php artisan schedule:run` every minute. Say so; it is the most common reason backups never happen.
        - **Notifications.** Set `BACKUP_NOTIFY_MAIL`. A backup nobody is told about failing is a backup
          nobody knows they do not have.
        - **Queue.** For large sites enable `queue.enabled` so a scheduled backup does not block the
          scheduler — and check a worker is running.

        ## Recipes

        **Nightly full backup, encrypted, to S3, keep 14:**
        ```
        config: {
          "profiles.default.type": "full",
          "profiles.default.destinations": ["s3"],
          "retention.simple.keep_latest": 14
        }
        env: {
          "BACKUP_ENCRYPTION": "aes-256-cbc",
          "BACKUP_ENCRYPTION_KEY": "base64:…32 random bytes…",
          "BACKUP_SCHEDULE": "true",
          "BACKUP_NOTIFY_MAIL": "ops@example.com"
        }
        ```

        **Hourly database-only profile alongside it:**
        ```
        config: {
          "profiles.hourly.name": "Hourly database",
          "profiles.hourly.type": "database",
          "profiles.hourly.destinations": ["s3"],
          "profiles.hourly.path": "backups/hourly",
          "profiles.hourly.retention": {"simple": {"keep_latest": 48}}
        }
        ```
        A second profile needs its own schedule entry in the application's routine
        (`Schedule::command('backup:run --profile=hourly')->hourly()`); the package's own
        `schedule.backup` expression only covers the default profile.

        ## Rules

        - Never write a value the reference does not allow; the writer will refuse it anyway.
        - Secrets belong in `.env`, never in the committed config file.
        - Deleting backups is off by default and stays off unless the user asks for it.
        - Restoring is not exposed over MCP at all: it overwrites a live application, so it is a
          deliberate human act — `php artisan backup:restore`.
        - Finish by telling the user what to check themselves: that the key is stored off the server,
          that the system cron runs the scheduler, and that a restore has been rehearsed at least once.
        GUIDE;
    }
}
