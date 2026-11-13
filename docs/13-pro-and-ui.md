# Pro & the UI dashboard

Backup Manager is **open-core**: the free `nyoncode/laravel-backup-manager`
package is a complete, CLI-first backup tool on its own. **Pro** and the **UI
dashboard** are commercial add-ons that plug in through the core's existing
contracts — installing them upgrades the same commands and managers, with no
code changes on your side.

If a feature below is marked **Pro**, the free core keeps the contract but fails
closed without the Pro package: it never silently produces a backup that is less
protected than you asked for.

## What's in each tier

| Capability | Free core | Pro |
| --- | :---: | :---: |
| Database + file backups, compression, AES-256 encryption | ✅ | ✅ |
| Integrity manifest + checksums | ✅ | ✅ |
| Multi-destination storage (any Laravel disk) | ✅ | ✅ |
| Restore with verify + rollback | ✅ | ✅ |
| Scheduling & queues | ✅ | ✅ |
| Retention: `simple` (keep-latest, age, count) | ✅ | ✅ |
| Mail notifications | ✅ | ✅ |
| **Incremental & deduplicated backups** | — | ✅ |
| **GFS retention** + total-size cap | — | ✅ |
| **HMAC manifest signing** (tamper-evidence) | — | ✅ |
| **Health monitoring** (`backup:monitor`) + history | — | ✅ |
| **Slack notifications** | — | ✅ |

The free core can *read and list* snapshots produced by Pro, but not create,
restore or verify them; requesting a Pro strategy (e.g. `retention.strategy =
gfs`) without Pro prunes nothing rather than doing the wrong thing.

## Upgrading to Pro

Pro is distributed from NyonCode.cz's Composer repository to licence holders. The
core ships a command that wires everything up from your licence key:

```bash
php artisan backup:upgrade
```

Run it without an argument and it prompts for the key (hidden). In automation, set
`BACKUP_PRO_TOKEN` instead:

```bash
BACKUP_PRO_TOKEN=<licence-key> php artisan backup:upgrade
```

> Avoid `php artisan backup:upgrade <licence-key>`. It still works, but the key
> lands in your shell history and is visible to other local users in `ps`. The
> command warns you when you do this.

A repository URL over plain `http` is refused, because the key is sent as HTTP
basic credentials (only `localhost` is exempt).

It points Composer at the repository, stores the key as the HTTP-basic password
and requires `nyoncode/laravel-backup-manager-pro`. The Pro service provider then
overrides the core's null bindings with the real snapshot driver, GFS strategy,
signer, monitor and Slack channel — the `backup:*` commands gain the features
above on the next run.

The **Backup Manager Pro** package documents its full feature list and roadmap.

## The UI dashboard

**Backup Manager UI** is a live Livewire dashboard for your backups — run,
browse, restore, verify and monitor every destination from one screen. It builds
on Pro (health monitoring, history and incremental snapshots), so it **requires
Pro**; there is no free-core build.

![Backup Manager UI dashboard — fleet health, per-destination sizes and last-run history, sorted attention-first](dashboard.png)

Highlights:

- **Fleet health at a glance** — every profile and destination, sorted
  attention-first, with per-disk size bars and a last-runs strip.
- **Freshness per profile** — a bar showing how much of the allowed age a
  profile has used, read from its schedule: "Expected every 2 weeks · next run in
  3 days", "Due now — backup expected" when a run is running late, and "Overdue
  by 2 days on s3" when the newest backup on disk is past its threshold. The
  thresholds are the same cadence-aware ones `backup:monitor` uses (see
  [Scheduling](10-scheduling.md)).
- **Mobile layout** — on narrow screens the tables collapse into cards.
- **One-click, safe actions** — run, verify and restore live; restores are
  rollback-safe and checksum-verified, exactly like the CLI.
- **Safe by default** — restore and retention are off until you enable them and
  are re-checked server-side, never merely hidden.
- **Multilingual** — English, Deutsch and Čeština, with an in-page switcher that
  never touches your app's own locale.

Install it after Pro (it pulls Pro and the core in automatically):

```bash
composer require nyoncode/laravel-backup-manager-ui
```

It mounts at `/backup-manager`. The **Backup Manager UI** package documents the
full setup, configuration and safety model.

### Inside a WireStack admin

With `nyoncode/wire-core` 2.x installed, the dashboard joins your WireStack 2
(wire-admin) panel with no glue code. Mount it under the admin's prefix and guard:

```dotenv
BACKUP_UI_PATH=admin/backups
```

```php
// config/backup-manager-ui.php
'middleware' => ['web', 'auth', 'can:backups.manage'],

'wirestack' => [
    'ability' => 'backups.manage',   // who sees the sidebar entry
],
```

Then:

- A **Backups** entry (children: Overview, Activity, Settings) is added to the
  sidebar through wire-core's `navigation.building` hook.
- The screens render inside the host layout (`layouts.admin`, written by
  `php artisan wire-admin:install`) and follow its theme.
- The entry carries a **red badge** with the number of profiles needing
  attention, as the dashboard last saw them. It is read from cache only — the
  menu never triggers a health check.

`wirestack.ability` only hides the menu entry; the routes keep their own
`middleware`, which is the real guard. Without it, any signed-in user sees the
entry.

All keys live under `backup-manager-ui.wirestack`:

| Key | Default | Meaning |
| --- | --- | --- |
| `enabled` | `'auto'` (`BACKUP_UI_WIRESTACK`) | `'auto'` turns it on when WireStack is installed; `true` / `false` force it |
| `layout` | `'layouts.admin'` | host layout component (ignored when `layout` below is set) |
| `key` | `'backups'` | key the entry is listed under |
| `label` | `null` | menu label; `null` is the translated "Backups" |
| `icon` | `'outline:archive-box'` | menu icon |
| `group` | `null` | a navigation group key, e.g. `'system'` |
| `sort` | `90` | menu order |
| `children` | `true` | show Overview / Activity / Settings under the entry |
| `ability` | `null` | Gate ability that shows the entry |
| `badge` | `true` | show the attention badge |

### Any host layout

Not on WireStack? Render the dashboard inside any layout component of your
application:

```dotenv
BACKUP_UI_LAYOUT=layouts.admin
```

(or `'layout' => 'layouts.admin'` in `config/backup-manager-ui.php`). The
dashboard drops its own header and follows the host's theme. A name that does not
resolve to a component falls back to the standalone dashboard rather than failing.
