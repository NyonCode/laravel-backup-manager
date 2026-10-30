---
title: AI assistants (MCP)
description: "Setting up backups is a small pile of decisions — what to include, where to send it, how to encrypt it, how long to keep it — and every one of them is easy…"
key: mcp
---
# AI assistants (MCP)

Setting up backups is a small pile of decisions — what to include, where to send
it, how to encrypt it, how long to keep it — and every one of them is easy to get
subtly wrong. This package can hand those decisions, and the facts they depend
on, to an AI assistant:

```bash
php artisan backup:mcp
```

That serves a [Model Context Protocol](https://modelcontextprotocol.io) server
over stdin/stdout. An assistant connected to it can read the installation,
propose a configuration, write it, run a backup and verify the result — using
exactly the same code paths as the CLI. No extra package is required: the server
ships with the free core.

> **The assistant never restores anything.** Restore overwrites a live
> application, so it stays a deliberate human act: `php artisan backup:restore`.

## Connecting a client

**Claude Code**

```bash
claude mcp add backup-manager -- php artisan backup:mcp
```

**Claude Desktop, Cursor, and other clients that take a JSON config**

```json
{
  "mcpServers": {
    "backup-manager": {
      "command": "php",
      "args": ["artisan", "backup:mcp"],
      "cwd": "/path/to/your/application"
    }
  }
}
```

The server writes protocol frames to stdout and nothing else — its own status
lines go to stderr, where clients show them as server logs. If your application
logs to stdout (`LOG_CHANNEL=stdout`), point it at `stderr` for this command, or
the client will see the log lines as malformed protocol.

## What the assistant can call

| Tool | Access | What it does |
|------|--------|--------------|
| `backup_setup_guide` | read | The order of work and the decisions that matter |
| `backup_overview` | read | Edition, profiles, encryption, schedule, health counts |
| `backup_inspect_environment` | read | Disks, database connections, binaries, free space |
| `backup_list_profiles` | read | Every profile as the pipeline resolves it |
| `backup_config_reference` | read | Every configuration key, type, allowed values |
| `backup_validate_configuration` | read | Everything wrong with the current setup, and the fix |
| `backup_list_backups` | read | What is actually stored, newest first |
| `backup_preview_retention` | read | What retention *would* delete |
| `backup_configure` | configure | Writes `.env` / `config/backup-manager.php` |
| `backup_run` | operate | Creates a backup |
| `backup_verify` | operate | Checks a stored backup against its manifest |
| `backup_apply_retention` | destructive | Deletes backups outside the policy |

The server also exposes the setup guide, the configuration reference and the
current (credential-free) configuration as MCP **resources**, and a
`set-up-backups` **prompt**.

## Permissions

```php
'mcp' => [
    'enabled' => (bool) env('BACKUP_MCP', true),
    'allow_writes' => (bool) env('BACKUP_MCP_ALLOW_WRITES', true),
    'allow_destructive' => (bool) env('BACKUP_MCP_ALLOW_DESTRUCTIVE', false),
    'environments' => ['local'],
],
```

Each tool declares an access level, and the server only exposes the levels the
installation permits — a tool that is not permitted is not listed, and calling it
anyway returns the reason and the flag that would enable it rather than a silent
failure.

- **read** and **operate** are always available.
- **configure** needs `allow_writes` (on by default).
- **destructive** needs `allow_destructive` (**off** by default). Only
  `backup_apply_retention` is at this level, and even then it dry-runs unless
  told otherwise.
- `environments` decides where the server runs at all; outside that list
  `backup:mcp` refuses to start. Use `['*']` to allow every environment.

Flags narrow a single session without touching configuration:

```bash
php artisan backup:mcp --read-only          # nothing may be written
php artisan backup:mcp --allow-destructive  # allow retention pruning
```

## How a configuration change is written

`backup_configure` takes dot paths from the reference and this package's
environment variables:

```json
{
  "config": { "profiles.default.destinations": ["s3"], "retention.simple.keep_latest": 14 },
  "env": { "BACKUP_ENCRYPTION": "aes-256-cbc", "BACKUP_SCHEDULE": true },
  "dry_run": true
}
```

What happens between that call and a changed file:

1. **Every key is checked against the reference.** An undocumented key, or a
   value of the wrong type or outside the allowed set, aborts the whole call —
   nothing is written, and the error names the nearest real key.
2. **The result is validated before it exists.** The change is applied to an
   in-memory copy of the configuration and run through the same checks as
   `backup_validate_configuration`. A dry run returns those findings, so a
   mistake is visible before it is written. Applying a change that would leave
   errors behind is refused unless the caller passes `force`.
3. **Each key goes to the right file.** A setting the config file reads through
   `env()` is written to `.env`, not baked into the PHP file as a literal —
   otherwise the setting would silently stop following the environment it is
   deployed with. Secrets are written but never echoed back.
4. **The config file is edited, not regenerated.** The source is tokenised and
   only the target value is replaced, so every comment and the formatting
   survive. A key that does not exist yet is inserted, creating intermediate
   levels as needed (this is how a whole new profile appears).
5. **The result is re-parsed before it is written**, the previous file is copied
   to `storage/app/backup-manager/config-backups/`, and the new one is written
   atomically. If any of that fails, nothing changes.

Finally, the values are mirrored into the running process, so a `backup_run` in
the same session uses what was just configured. Other processes pick the change
up on their next boot — or after `php artisan config:clear` if your application
caches its configuration, which the tool tells you.

## Using it well

Ask for what you want in plain words — "set up nightly encrypted backups to S3,
keep two weeks" — and expect the assistant to:

1. read the guide, the environment and the reference before proposing anything,
2. show you a dry run,
3. apply it, then **run and verify a real backup**.

Two things stay yours, and a good assistant will say so:

- **The encryption key.** Store a copy somewhere other than the server. Without
  it, no backup can ever be restored.
- **The scheduler.** `schedule.enabled` registers the commands with Laravel's
  scheduler, which only runs if the system cron calls `php artisan schedule:run`
  every minute.

And one thing no assistant can do for you: rehearse a restore. A backup that has
never been restored is a hope, not a backup.

## Adding your own tool

Tools are collected from a container tag, so an application or an add-on can
contribute one without touching the server:

```php
use Nyoncode\BackupManager\BackupManagerServiceProvider;

$this->app->tag([MyTool::class], BackupManagerServiceProvider::MCP_TOOLS);
```

`MyTool` implements `Nyoncode\BackupManager\Contracts\Mcp\McpTool` (extending
`Nyoncode\BackupManager\Mcp\Tools\Tool` gives you the schema helpers) and
declares an `McpToolAccess` level, which the same permission rules then apply to.
