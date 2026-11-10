# Devuni Notifier Agent

[![Latest Version on Packagist](https://img.shields.io/packagist/v/devuni/notifier-agent.svg?style=flat-square)](https://packagist.org/packages/devuni/notifier-agent)
[![Tests](https://github.com/devuni-cz/notifier-agent/actions/workflows/ci.yml/badge.svg)](https://github.com/devuni-cz/notifier-agent/actions/workflows/ci.yml)
[![PHP 8.4 | 8.5](https://img.shields.io/badge/PHP-8.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white)](https://www.php.net)
[![Laravel 12 | 13](https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![License: MIT](https://img.shields.io/badge/license-MIT-green?style=flat-square)](LICENSE.md)

Laravel client for the [Devuni Notifier](https://notifier.devuni.cz) control plane.

-   Encrypted database and storage backups, uploaded to the control plane.
-   Restore of this site's own backups.
-   Heartbeat that tells the control plane the site is alive.
-   Maintenance announcements in your dashboard.

Without a control plane (`NOTIFIER_URL`) there is nowhere to send backups. For standalone backups use [spatie/laravel-backup](https://github.com/spatie/laravel-backup).

## Requirements

-   PHP 8.4+
-   Laravel 12.55+ or 13.14+
-   `7z` (`p7zip-full`, recommended) or the PHP `zip` extension
-   The client tools for your database:

| Driver             | Backup                                | Restore                            |
| ------------------ | ------------------------------------- | ---------------------------------- |
| `mysql`, `mariadb` | `mysqldump`                           | `mysql`                            |
| `pgsql`            | `pg_dump`, or `ysql_dump` (YugabyteDB) | `psql`, or `ysqlsh` (YugabyteDB)   |

-   MariaDB uses the same `mysqldump` and `mysql` commands. `mariadb-dump` alone is not enough.
-   Database restore also needs the backup tool, for its safety dump (unless `--no-snapshot`).
-   PostgreSQL restore: use a `psql` at least as new as the `pg_dump` that made the backup. 13.22, 14.19, 15.14, 16.10, 17.6 and 18+ also run in restricted mode; older clients log a warning.

## Install

```bash
composer require devuni/notifier-agent
```

```bash
php artisan notifier:install
```

```bash
php artisan notifier:check
```

-   `notifier:install` writes `NOTIFIER_URL`, `NOTIFIER_BACKUP_CODE` and `NOTIFIER_BACKUP_PASSWORD` to `.env`. It can also set `NOTIFIER_RESTORE_TOKEN` and `NOTIFIER_TRIGGER_SECRET`.
-   Keep a copy of `NOTIFIER_BACKUP_PASSWORD` outside the server. Without it no backup can be restored.
-   `notifier:check` tests the env, restore token, database, storage folders, dump tool, ZIP tools, seal, log channel, queue and whether the server answers. It doesn't test the backup code.
-   A new Laravel app has no `backup` log channel. Add one to `config/logging.php`, or logs go to `daily`.
-   Publish the config only to change exclusions: `php artisan vendor:publish --tag=notifier-config`.

## Scheduling

Pick one mode:

| Mode           | For                         | Schedule                                  |
| -------------- | --------------------------- | ----------------------------------------- |
| Control plane  | Devuni-managed sites (default) | `notifier:heartbeat` only               |
| Self-scheduled | Standalone sites            | Backup commands and `notifier:heartbeat`  |

-   Control plane: it calls the [trigger endpoint](#trigger-endpoint). Don't also schedule the backup commands, or every backup runs twice.
-   Always schedule the heartbeat.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('notifier:heartbeat')->hourly()->onOneServer();

// Self-scheduled mode only:
Schedule::command('notifier:database-backup')->dailyAt('02:00')->onOneServer();
Schedule::command('notifier:storage-backup')->weeklyOn(0, '03:00')->onOneServer();
```

## Commands

| Command                     | Does                                                        |
| --------------------------- | ----------------------------------------------------------- |
| `notifier:install`          | `.env` wizard. On a configured site it needs `--force`; current values are prefilled. |
| `notifier:check`            | Checks the setup.                                           |
| `notifier:database-backup`  | Dumps, encrypts, seals and uploads the database.            |
| `notifier:storage-backup`   | Same for `storage/app/public`.                              |
| `notifier:heartbeat`        | Sends the heartbeat.                                        |
| `notifier:database-restore` | Restores a database backup.                                 |
| `notifier:storage-restore`  | Restores a storage backup.                                  |

## Restore

```bash
php artisan notifier:database-restore --list
```

```bash
php artisan notifier:database-restore --dry-run
```

```bash
php artisan notifier:database-restore
```

| Option                         | Does                                                       |
| ------------------------------ | ---------------------------------------------------------- |
| `--list`                       | Lists available backups.                                   |
| `--id=N`                       | Restores this backup. Default: the newest.                 |
| `--dry-run`                    | Downloads, verifies and checks. Changes nothing.           |
| `--force`                      | Skips the confirmation. Required in production.            |
| `--accept-unverified=<sha256>` | Approves one backup without a valid seal. Needs `--id`.    |
| `--no-snapshot`                | Database only: skips the dump of the current database.     |

-   Needs `NOTIFIER_RESTORE_TOKEN`. It is separate from the backup code.
-   The seal is checked before anything is extracted.
-   No valid seal (backups before 1.9.0, sites without a password, another site's backup): refused. The refusal prints the `--id` and `--accept-unverified` values that approve that file. `--force` and config can't replace them.
-   Without `--id`, the newest backup must have been sealed in the last 35 days.
-   An approved unsealed storage backup goes to `storage/app/notifier-restore/`. Copy what you need by hand.
-   Database restore asks for the database name, then dumps the current database to `storage/app/notifier-restore/` before it overwrites it.
-   That safety dump is deleted after success. If the import fails, MySQL and MariaDB are put back from it. PostgreSQL rolls back itself; the dump is kept if the rollback can't be confirmed.
-   Storage restore adds and overwrites files. It never deletes.
-   Storage restore refuses a backup that contains `.php`, `.htaccess` or other executable or server config files.
-   Downloads stop at `NOTIFIER_RESTORE_MAX_DOWNLOAD_BYTES` (default 20 GiB).

### New deployment

1.  Set `.env` with the original `NOTIFIER_BACKUP_PASSWORD`, the same `NOTIFIER_URL` and a `NOTIFIER_RESTORE_TOKEN`.
2.  Run `notifier:database-restore --force` and `notifier:storage-restore --force`.
3.  Start backups after the restore. The control plane rejects near-empty backups.

-   A new password can't decrypt old backups.
-   A different `NOTIFIER_URL` points at another repository with other backups.

## Configuration

Required:

| Key                        | Value                                                                  |
| -------------------------- | ---------------------------------------------------------------------- |
| `NOTIFIER_URL`             | Repository endpoint, e.g. `https://notifier.devuni.cz/api/v1/repositories/123` |
| `NOTIFIER_BACKUP_CODE`     | Token for requests to the control plane.                               |
| `NOTIFIER_BACKUP_PASSWORD` | Encrypts and seals backups. Never change it on a live site.            |

Optional:

| Key                                       | Default                    | Does                                                          |
| ----------------------------------------- | -------------------------- | ------------------------------------------------------------- |
| `NOTIFIER_RESTORE_TOKEN`                  | none                       | Enables restore.                                              |
| `NOTIFIER_TRIGGER_SECRET`                 | the backup code            | Token for the trigger endpoint. Must match the control plane. |
| `NOTIFIER_DATABASE_CONNECTION`            | default connection         | Connection to back up and restore.                            |
| `NOTIFIER_POSTGRES_DUMP_BINARY`           | `ysql_dump`, else `pg_dump` | Dump tool for `pgsql`.                                       |
| `NOTIFIER_POSTGRES_RESTORE_BINARY`        | `ysqlsh`, else `psql`      | Restore client for `pgsql`.                                   |
| `NOTIFIER_POSTGRES_SCHEMA`                | `public`                   | Schema for unqualified excluded tables. Restore resets it.    |
| `NOTIFIER_ZIP_STRATEGY`                   | `auto`                     | `auto`, `cli` (7z) or `php`.                                  |
| `NOTIFIER_SEAL_BACKUPS`                   | `true`                     | `false` stops sealing new backups.                            |
| `NOTIFIER_CHUNK_SIZE`                     | 20 MiB                     | Upload chunk size. Keep under 100 MB behind Cloudflare Free.  |
| `NOTIFIER_MIN_STORAGE_BACKUP_BYTES`       | `102400`                   | Smaller storage backups are skipped.                          |
| `NOTIFIER_QUEUE_CONNECTION`               | `sync`                     | Queue for triggered backups. Commands always run inline.      |
| `NOTIFIER_LOGGING_CHANNEL`                | `backup`                   | Falls back to `daily` if the channel doesn't exist.           |
| `NOTIFIER_ROUTES_ENABLED`                 | `true`                     | Registers the trigger endpoint.                               |
| `NOTIFIER_ROUTE_PREFIX`                   | `api/notifier`             | Prefix of the trigger endpoint.                               |
| `NOTIFIER_BACKUPS_ENABLED`                | `true`                     | Reported in the heartbeat.                                    |
| `NOTIFIER_HEARTBEAT_ENABLED`              | `true`                     | `false` makes `notifier:heartbeat` do nothing.                |
| `NOTIFIER_RESTORE_MAX_DOWNLOAD_BYTES`     | 20 GiB                     | Restore download limit. `0` = none.                           |
| `NOTIFIER_RESTORE_MAX_EXTRACTED_BYTES`    | 10 GiB                     | Extracted size limit. `0` = none.                             |
| `NOTIFIER_RESTORE_DISK_FREE_MARGIN_BYTES` | 256 MiB                    | Free space kept during extraction.                            |
| `NOTIFIER_RESTORE_MAX_COMPRESSION_RATIO`  | `0` (off)                  | Refuses archives that compress better than this.             |

Announcement keys are under [Announcements](#announcements).

### Exclusions

Edit the published `config/notifier.php`:

```php
'excluded_tables' => ['telescope_entries', 'telescope_entries_tags'],
'excluded_files' => ['.gitignore', '.gitkeep', 'temp'],
```

-   Tables: plain names, or `schema.table` (`database.table` on MySQL and MariaDB).
-   Files: relative to `storage/app/public`.
-   PostgreSQL: a restore drops and recreates `NOTIFIER_POSTGRES_SCHEMA`. Anything in it that isn't in the backup is gone, excluded tables included.
-   PostgreSQL: exclude both tables of a foreign key or neither, or the restore fails.
-   PostgreSQL: other schemas are not reset. If they already exist in the target, the restore fails.

## Trigger endpoint

The control plane starts backups through this endpoint.

```bash
curl -X POST https://your-app.com/api/notifier/backup \
  -H "X-Notifier-Token: <trigger secret>" \
  -d "type=backup_database"
```

-   `type`: `backup_database` or `backup_storage`. Anything else gets `422`.
-   Token: `NOTIFIER_TRIGGER_SECRET`, or the backup code if that isn't set.
-   A missing or wrong token gets `403`. So does every request while a required `.env` key is missing; the reason is in the log.
-   10 requests per hour per IP, failed ones included. Over the limit: `429`.
-   Queue: with `NOTIFIER_QUEUE_CONNECTION` other than `sync`, the backup runs on that queue and the endpoint answers `200` at once. A queue worker must run.
-   One backup per type at a time. Without a queue a second one gets `429`; with a queue it gets `200` and is not queued.
-   Without a queue, a failed backup returns `500` with an `error_id`. Details are in the log channel.

## Heartbeat

-   Sends agent, PHP and Laravel versions, queue connection, enabled features, disk space and last backup times.
-   The control plane marks a site stale after 6 h without one. Schedule it hourly.
-   Exits non-zero when the heartbeat isn't delivered.

## Announcements

-   On by default. Nothing is fetched until `NOTIFIER_URL` is set.
-   Filament: a banner is added to every panel page automatically.
-   Blade: `<x-notifier-announcements-notice />`.
-   Inertia, Vue, React:

```php
$service = app(\Devuni\Notifier\Services\AnnouncementsService::class);

$service->activeAnnouncements();               // all active announcements
$service->customAnnouncements('spa-banner');   // dashboard_type "custom", for one element id
```

-   Cached. A failed fetch shows nothing and never breaks the page.
-   Own markup: `php artisan vendor:publish --tag=notifier-views`.

| Key                                        | Default                 | Does                                                   |
| ------------------------------------------ | ----------------------- | ------------------------------------------------------ |
| `NOTIFIER_ANNOUNCEMENTS_ENABLED`           | `true`                  | Turns announcements on or off.                         |
| `NOTIFIER_ANNOUNCEMENTS_CACHE_TTL`         | `900`                   | Seconds a response is cached.                          |
| `NOTIFIER_ANNOUNCEMENTS_FAILURE_CACHE_TTL` | `300`                   | Seconds a failed fetch is cached.                      |
| `NOTIFIER_ANNOUNCEMENTS_TIMEOUT`           | `5`                     | Request timeout in seconds.                            |
| `NOTIFIER_ANNOUNCEMENTS_MAX_VISIBLE`       | `5`                     | Banners per location. `0` = all.                       |
| `NOTIFIER_ANNOUNCEMENTS_FILAMENT`          | `true`                  | Adds the banner to Filament panels.                    |
| `NOTIFIER_ANNOUNCEMENTS_FILAMENT_HOOK`     | `panels::content.start` | Default render hook.                                   |
| `NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS`      | the default hook        | Comma-separated hooks an announcement's `target` may use. |

## Migrating from `devuni/notifier-package`

```bash
composer remove devuni/notifier-package
```

```bash
composer require devuni/notifier-agent
```

-   Same namespace, env keys, commands, config and routes.
-   Don't install both. Composer refuses it.
-   Versions restart at 1.0.0 and include everything from 2.8.0.
-   Old `BACKUP_CODE`, `BACKUP_URL` and `BACKUP_ZIP_PASSWORD` still work as fallbacks.
-   From 2.7.x or older: announcements are on by default. `NOTIFIER_ANNOUNCEMENTS_ENABLED=false` turns them off.

## Security

-   AES-256 archives with `0600` permissions, deleted after upload.
-   HTTPS only. Requests carrying the token never follow redirects.
-   Token compared with `hash_equals`. SHA-256 per chunk and per file.
-   The ZIP password goes through stdin, never the process list.
-   Report vulnerabilities privately, see [SECURITY.md](SECURITY.md).

## Links

-   [Changelog](CHANGELOG.md)
-   [Contributing](CONTRIBUTING.md)
-   [Config with comments](config/notifier.php)

## License

MIT, see [LICENSE.md](LICENSE.md). By [Ludwig Tomas](https://github.com/ludwigtomas) and [contributors](https://github.com/devuni-cz/notifier-agent/graphs/contributors).
