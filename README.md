# Devuni Notifier Agent

[![Latest Version on Packagist](https://img.shields.io/packagist/v/devuni/notifier-agent.svg?style=flat-square)](https://packagist.org/packages/devuni/notifier-agent)
[![Tests](https://github.com/devuni-cz/notifier-agent/actions/workflows/ci.yml/badge.svg)](https://github.com/devuni-cz/notifier-agent/actions/workflows/ci.yml)
[![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![Laravel 12](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)

Encrypted database & storage backups for Laravel apps, shipped to the [Devuni Notifier](https://notifier.devuni.cz) central server. AES-256 ZIPs, chunked HTTPS upload, token auth, queue support. Supports **MySQL**, **MariaDB**, **PostgreSQL**, and **YugabyteDB** (via YSQL).

## How it works

```
┌─────────────────────┐        encrypted ZIP         ┌─────────────────────┐
│  Your Laravel app   │  ───── chunked upload ─────▶ │  notifier.devuni.cz │
│  (this package)     │        (X-Notifier-Token)    │  (central server)   │
└─────────────────────┘                              └─────────────────────┘
         │                                                     │
         │ mysqldump + storage/app/public                      │ stores + monitors
         │ → AES-256 ZIP                                       │ → sends alerts
         ▼                                                     ▼
    local temp file                                     long-term backup archive
    (cleaned up after upload)
```

> **Heads up:** This is the **client side** of the Devuni Notifier platform. Without a central server configured via `NOTIFIER_URL`, there's nowhere to send backups. If you don't have it, try [spatie/laravel-backup](https://github.com/spatie/laravel-backup) instead.

## Install

```bash
composer require devuni/notifier-agent
php artisan vendor:publish --tag="notifier-config"
php artisan notifier:install   # interactive .env wizard
php artisan notifier:check     # verify setup (env, DB, 7z, mysqldump, URL)
```

**Requirements:** PHP 8.4+, Laravel `^12.55` or `^13.14`, the right dump tool for your DB (`mysqldump` / `mariadb-dump` for MySQL & MariaDB, `pg_dump` or `ysql_dump` for PostgreSQL & YugabyteDB), and `p7zip-full` (recommended) or PHP `zip` extension.

### Migrating from `devuni/notifier-package`

This package is the successor of [`devuni/notifier-package`](https://github.com/devuni-cz/notifier-package) — same namespace (`Devuni\Notifier\`), same env vars (`NOTIFIER_*`), same artisan commands, same `config/notifier.php`, same route prefix. The swap is one step:

```bash
composer remove devuni/notifier-package
composer require devuni/notifier-agent
```

Nothing else changes — your published config, `.env`, and scheduled `notifier:*` commands keep working as-is. Don't be confused by the version number: `notifier-agent` restarts at `1.0.0`, but it contains everything `notifier-package` `2.8.0` had (and newer).

> **Never install both packages side by side** — they share the `Devuni\Notifier\` namespace. Composer refuses the combination (a `conflict` rule guards it), so if you see a conflict error during `composer require`, run the `composer remove` step first.

Heads-up when coming from `notifier-package` ≤ 2.7.x: version 2.8.0+ introduced **announcements**, which are enabled by default (see below). If you don't want the banner, set `NOTIFIER_ANNOUNCEMENTS_ENABLED=false`.

## Usage

### Scheduled backups (recommended)

Add to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifier:database-backup')->dailyAt('02:00')->onOneServer();
Schedule::command('notifier:storage-backup')->weeklyOn(0, '03:00')->onOneServer();
```

### On demand

```bash
php artisan notifier:database-backup
php artisan notifier:storage-backup
```

### HTTP API

Trigger backups from an external scheduler. Rate-limited to 10 req/hour.

```bash
curl -X POST https://your-app.com/api/notifier/backup \
  -H "X-Notifier-Token: your-token" \
  -d "type=backup_database"   # or backup_storage
```

On failure the response returns an opaque `error_id` (UUID) - the full detail (stack trace, `mysqldump`/7z stderr) stays in your `backup` log channel. Grep logs for the UUID to correlate.

### Announcements

Pull this site's maintenance/announcement notices from the central server and show them in your dashboard. **On by default** - it costs nothing until `NOTIFIER_URL` is set (the service no-ops without a target). Disable with `NOTIFIER_ANNOUNCEMENTS_ENABLED=false`.

**Filament hosts** (the common case): when Filament is installed, the notices are **auto-injected** as a banner into every panel page via a render hook - nothing to place. Move the default spot with `NOTIFIER_ANNOUNCEMENTS_FILAMENT_HOOK` (default `panels::content.start`; also `panels::body.start`, `panels::topbar.end`), or turn the auto-injection off with `NOTIFIER_ANNOUNCEMENTS_FILAMENT=false`. Works across Filament v3/v4/v5.

**Per-announcement placement:** each announcement carries a `target` from the control plane. A Filament announcement is routed to the render hook named in its `target`; with no `target` it falls to the default hook above. The package only injects at the hooks you opt into, so to support more than one position list them all (comma-separated) in `NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS` - the default hook is always included:

```bash
NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS="panels::content.start,panels::topbar.end"
```

Existing setups need no change: with no `target` (or older servers) every announcement keeps rendering at the default hook exactly as before.

**Blade hosts:** drop the component anywhere:

```blade
<x-notifier-announcements-notice />
```

**Inertia / Vue / React (custom SPA) hosts:** the service is framework-agnostic - render it yourself. Announcements aimed at a custom dashboard arrive with `dashboard_type` `custom` and a `target` DOM element id; fetch just those and mount each at its `target`:

```php
// All active announcements (any dashboard_type):
app(\Devuni\Notifier\Services\AnnouncementsService::class)->activeAnnouncements();

// Only the custom (SPA) ones, optionally for a single element id:
app(\Devuni\Notifier\Services\AnnouncementsService::class)->customAnnouncements();
app(\Devuni\Notifier\Services\AnnouncementsService::class)->customAnnouncements('spa-banner');
// e.g. share as an Inertia prop, then render each item into the element named by its 'target'.
```

Requests are **per-repository** and reuse your existing `NOTIFIER_URL` + `X-Notifier-Token` (`GET {NOTIFIER_URL}/announcements`), so the server returns only this site's announcements - no other repositories are disclosed. Responses are cached (`NOTIFIER_ANNOUNCEMENTS_CACHE_TTL`, default 900 s) so the dashboard never blocks on a live request, and any fetch failure renders nothing rather than breaking your dashboard. Customize the Blade markup with `vendor:publish --tag="notifier-views"`.

## Configure

Minimum `.env`:

```bash
NOTIFIER_BACKUP_CODE=...                                        # auth token
NOTIFIER_URL=https://notifier.devuni.cz/api/v1/repositories/123 # your endpoint
NOTIFIER_BACKUP_PASSWORD=...                                    # ZIP password
```

Optional: `NOTIFIER_LOGGING_CHANNEL`, `NOTIFIER_ROUTES_ENABLED`, `NOTIFIER_ROUTE_PREFIX`, `NOTIFIER_ZIP_STRATEGY` (`auto`/`cli`/`php`), `NOTIFIER_CHUNK_SIZE`, `NOTIFIER_QUEUE_CONNECTION`, `NOTIFIER_DATABASE_CONNECTION`, `NOTIFIER_POSTGRES_DUMP_BINARY`, `NOTIFIER_POSTGRES_SCHEMA`. See [`config/notifier.php`](config/notifier.php) for defaults and descriptions.

### Database engine

The package auto-detects which dump tool to use from your Laravel connection driver:

| Driver | Tool used | Install |
|---|---|---|
| `mysql`, `mariadb` | `mysqldump` | `apt install mysql-client` or `mariadb-client` |
| `pgsql` (PostgreSQL) | `pg_dump` | `apt install postgresql-client` |
| `pgsql` (YugabyteDB) | `ysql_dump` if installed, else `pg_dump` | [YugabyteDB tools](https://docs.yugabyte.com) |

By default the package backs up your app's **default** Laravel connection (`config('database.default')`). Override with `NOTIFIER_DATABASE_CONNECTION=pgsql` if you want a different one.

For PostgreSQL/Yugabyte, force a specific binary via `NOTIFIER_POSTGRES_DUMP_BINARY=ysql_dump` (or `pg_dump`). Non-`public` schemas: set `NOTIFIER_POSTGRES_SCHEMA=myschema`.

### Exclusions

Arrays - edit `config/notifier.php`:

```php
'excluded_tables' => ['telescope_entries', 'sessions', 'cache', 'jobs', 'failed_jobs'],
'excluded_files'  => ['.gitignore', 'temp', 'logs/debug.log'],
```

### Queue offloading

API-triggered backups can be offloaded to avoid PHP timeouts:

```bash
NOTIFIER_QUEUE_CONNECTION=redis   # or database, sqs, beanstalkd
```

Artisan commands always run synchronously regardless of this setting.

## Security

- **At rest:** AES-256 encrypted archives with `0600` permissions, cleaned up after upload
- **In transit:** HTTPS-only, `hash_equals` token comparison, per-chunk + full-file SHA-256 verification
- **No leaks:** ZIP password passed via stdin (not argv - invisible to `ps` / `/proc/*/cmdline`); API errors return opaque UUIDs, not raw exception messages
- **Report vulnerabilities:** see [security policy](../../security/policy) - don't open public issues

## Links

- [Changelog](CHANGELOG.md) - see what's new in each release
- [Contributing](CONTRIBUTING.md)
- [Full config reference](config/notifier.php)

## Credits

- [Ludwig Tomas](https://github.com/ludwigtomas)
- [All contributors](../../contributors)

## License

MIT - see [LICENSE.md](LICENSE.md).
