# Changelog

All notable changes to `devuni/notifier-agent` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.9.0] - 2026-11-10

### Upgrading

-   Backups made before 1.9.0 have no seal. Restoring one needs `--id=N` and `--accept-unverified=<sha256>`; the refused restore prints both.
-   `--force` does not replace that approval. Sites without a backup password need it too.
-   Upgrade production before staging.
-   Custom `DatabaseImporterInterface` implementations need `inspect(string $sqlPath): void`.
-   Custom `ZipExtractorInterface::extract()` implementations need a fourth argument, `bool $sealVerified = false`.

### Added

-   Backups made with a password are sealed in the ZIP comment. The archive stays a normal ZIP.
-   `NOTIFIER_SEAL_BACKUPS=false` stops sealing new backups. It does not relax restores.
-   `notifier:check` tests sealing; the heartbeat reports `sealed_backups`.
-   `NOTIFIER_RESTORE_MAX_DOWNLOAD_BYTES` caps restore downloads (default 20 GiB, `0` = no limit).

### Changed

-   Restores verify the archive before asking for confirmation.
-   An approved restore of unsealed storage goes to a private directory, not to public storage.
-   Without `--id`, a restore picks the newest backup only if its seal is under 35 days old.
-   PostgreSQL restores run `psql` in restricted mode (13.22, 14.19, 15.14, 16.10, 17.6, 18+). Older clients and `ysqlsh` log a warning.
-   Guzzle 8 allowed (`^7.15.2 || ^8.3.1`).
-   `nesbot/carbon` conflict narrowed to `3.12.0` only.
-   Dev: Pest 5 / PHPUnit 13 on Laravel 13, Larastan 3.13, Pint 1.32, Rector 2.8, Testbench 11.3.
-   Rector PHP 8.2-8.4 refactors in `src/`.
-   README: the control plane schedules backups by default; self-scheduling is for standalone sites.

### Fixed

-   Dumps from current `pg_dump` (13.22, 14.19, 15.14, 16.10, 17.6+) restore again.
-   PostgreSQL dumps whose data looks like a `psql` command are no longer refused.
-   `notifier:database-restore` checks the dump before taking a snapshot; `--dry-run` runs the same checks.
-   A failed PostgreSQL import no longer replays the snapshot. The snapshot is deleted if `psql` confirms the rollback, kept otherwise. MySQL and MariaDB still replay it.
-   7-Zip AES archives restore with libzip < 1.9 (Ubuntu 22.04, 24.04) without 7z installed.
-   A failed archive entry is reported by name.
-   Storage restores include files in `.git`, `.svn` and similar directories.
-   `--id` must be a positive integer (other values were read as `0`).

## [1.8.0] - 2026-08-05

### Added

-   README documents the restore commands and bootstrapping a fresh deployment ([#27](https://github.com/devuni-cz/notifier-agent/issues/27)).
-   Bootstrapping: keep the original `NOTIFIER_BACKUP_PASSWORD`, restore first, then start backups (a near-empty database is rejected as too small).
-   `notifier:install` can set `NOTIFIER_RESTORE_TOKEN` and a dedicated trigger secret. Both default to skip.
-   `notifier:install --force` prefills current values and keeps the backup password by default.
-   The restore token prompt hides input and rejects the backup code or password.
-   `notifier:check` warns when the restore token is missing or equals the backup code, and shows the trigger mode.

## [1.7.3] - 2026-08-05

### Security

-   `guzzlehttp/guzzle` `^7.15.2` (CVE-2026-69246, CVE-2026-69245).

### Fixed

-   Laravel 12 installs can no longer pull `nesbot/carbon` 3.12+, which crashed on boot (composer `conflict`).
-   Concurrent backups no longer overwrite each other's archive. Names are now `backup-<type>-<timestamp>-<random>.zip`.
-   Trigger-driven backups update the heartbeat's last backup time.
-   `ProcessBackupJob` checks the environment first, so a missing `NOTIFIER_BACKUP_PASSWORD` can't produce an unencrypted upload.
-   Near-empty storage backups are skipped instead of failing on the server. Threshold: `NOTIFIER_MIN_STORAGE_BACKUP_BYTES` (default 102400).
-   `.gitkeep` is excluded from storage backups by default.
-   CHANGELOG footer links fixed.

## [1.7.2] - 2026-07-27

### Security

-   Backup trigger: one running backup per type (`Cache::lock`, 900 s). A second trigger of the same type gets `429`.
-   `ProcessBackupJob` is unique per type, so a flood of triggers queues one job per type.

## [1.7.1] - 2026-07-23

### Security

-   `guzzlehttp/guzzle` `^7.15.1`, which also pulls `guzzlehttp/psr7` 2.13+. Clears nine advisories (seven Guzzle, two psr7).

## [1.7.0] - 2026-07-23

### Added

-   `notifier:database-restore` and `notifier:storage-restore` download this site's backups and apply them.
    -   Needs a separate `NOTIFIER_RESTORE_TOKEN`. Without it, restore is disabled.
    -   Options: `--list`, `--dry-run`, `--id=` (default newest), `--force` (required in production).
    -   Database restore asks for the database name and takes a snapshot first (`--no-snapshot` skips it).
    -   Storage restore adds and overwrites files, never deletes.
-   Restore checks: SHA-256 against the server's record, encrypted entries only, size cap, no path traversal, SQL dumps with client directives refused.
-   `NOTIFIER_BACKUPS_ENABLED` drives `features.backups` in the heartbeat.

### Changed

-   Announcement fallbacks match the config defaults (enabled, failed fetch cached 300 s). Affects sites with an old published config.

### Removed

-   Unused `BackupTypeEnum::values()` and `validationRule()`.

### Internal

-   CI covers PHP 8.5 + Laravel 12.
-   Fixed CHANGELOG links, README badge and `VERSION_MANAGEMENT.md`.

## [1.6.2] - 2026-06-18

### Fixed

-   An empty or failed storage archive (< 100 B) is skipped with a warning, not reported as success.
-   The heartbeat's last backup time is set only after an upload completes.

### Changed

-   Backup commands exit `1` with a readable message when the archive can't be created.
-   One output style for `notifier:check`, the backup commands, `notifier:heartbeat` and `notifier:install`.
-   `notifier:install` masks secrets in its summary.
-   An unwritable storage path prints a fix hint.
-   No colours or logo when output isn't a terminal.

## [1.6.1] - 2026-06-17

### Security

-   Request ID validation no longer accepts a trailing newline.
-   The server's upload `status` is stripped of control characters and length-capped before logging.

## [1.6.0] - 2026-06-17

### Added

-   All HTTP calls of one backup share an `X-Request-Id`.
-   On a server `5xx`, the failure message includes the server's `error_id` and `request_id` (sanitized).
-   Upload logs include `stage`, `upload_id`, `attempt`, `chunk_number` and `status`.

### Changed

-   Upload failures log at `error`, not `emergency`.

## [1.5.0] - 2026-06-16

### Added

-   Outgoing requests are signed: `X-Notifier-Timestamp`, `X-Notifier-Nonce`, `X-Notifier-Signature` (HMAC-SHA256). Servers that don't check them are unaffected.
-   `NOTIFIER_TRIGGER_SECRET` for inbound backup triggers. Falls back to `NOTIFIER_BACKUP_CODE`.
-   `notifier:install` requires a backup password of 16+ characters (6+ distinct) and generates one by default.

### Security

-   `release.yml` refuses unauthorized publishes.

### Fixed

-   `PhpZipCreator` on Windows writes entry names with forward slashes.

## [1.4.1] - 2026-06-15

### Added

-   `NOTIFIER_ANNOUNCEMENTS_MAX_VISIBLE` (default 5, `0` = unlimited) limits banners per location and adds a `+ N dalších oznámení` line. `customAnnouncements()` still returns all.

### Changed

-   The validity window uses a plain hyphen instead of an en dash.

## [1.4.0] - 2026-06-15

### Added

-   Announcements show their validity window (`Platí: {od} – {do}` or `Platí od {od} (do odvolání)`) in the app timezone. Omitted without a start date.

## [1.3.0] - 2026-06-14

### Added

-   `notifier:heartbeat` sends versions, queue, features, disk space and last backup times to the control plane. Schedule it hourly; turn it off with `NOTIFIER_HEARTBEAT_ENABLED=false`.
-   The server marks a site stale after 6 h without a heartbeat.
-   Backup commands record their last successful run for the heartbeat.

## [1.2.0] - 2026-06-14

### Added

-   Announcements have a `dashboard_type` (`filament` or `custom`) and a `target`.
-   Filament: each announcement goes to the render hook in `target`. List hooks in `NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS`.
-   SPA hosts: `AnnouncementsService::customAnnouncements(?string $target)`.
-   Older servers and existing setups behave as before.

## [1.1.0] - 2026-06-11

### Added

-   Announcement types (`maintenance`, `outage`, `release`, `notice`) with a label and a `notifier-announcement--type-{type}` CSS class.
-   `data-announcement-id` on each announcement.

## [1.0.2] - 2026-06-11

### Security

-   The rate limit runs before the token check, so token guessing is throttled (10/hour).
-   The backup endpoint returns the same `403` for a missing token, a wrong token and a misconfiguration. Details go to the log.
-   The plain `.sql` dump is `0600` and deleted even when zipping fails.
-   `notifier:install` quotes and escapes values written to `.env`.

## [1.0.1] - 2026-06-10

### Added

-   Composer `conflict` with `devuni/notifier-package`.
-   README: migrating from `devuni/notifier-package`.

### Changed

-   `NotifierApiClient` sends `Accept: application/json`.
-   Announcement `failure_cache_ttl` default raised from 60 s to 300 s.

### Tests

-   Token-bearing requests are tested never to follow redirects.
-   Announcement fixtures use real severity values.

## [1.0.0] - 2026-06-10

First release of `devuni/notifier-agent`, the Laravel client for the [Devuni Notifier](https://notifier.devuni.cz) control plane. It replaces `devuni/notifier-package` 2.8.0.

### Added

-   `notifier:database-backup` and `notifier:storage-backup`: AES-256 ZIPs (7z or PHP zip), chunked upload with SHA-256 per chunk.
-   MySQL, MariaDB, PostgreSQL and YugabyteDB. Table and file exclusions. Queue offloading with `NOTIFIER_QUEUE_CONNECTION`.
-   Announcements from the control plane, cached and fail-open: Filament banner, `<x-notifier-announcements-notice />` or `activeAnnouncements()`.
-   `NotifierApiClient`: HTTPS only, no redirects with the token.
-   `notifier:install` and `notifier:check`.
-   `POST {prefix}/backup` trigger endpoint, token-checked and rate-limited.

### Security

-   ZIP password via stdin. Archives are `0600` and deleted after upload.
-   SHA-256 per chunk and per file. `status_url` origin is checked. Server messages are sanitized.

### Notes

-   Namespace `Devuni\Notifier\`, env keys `NOTIFIER_*`.
-   Migrate with `composer remove devuni/notifier-package && composer require devuni/notifier-agent`.

[Unreleased]: https://github.com/devuni-cz/notifier-agent/compare/v1.9.0...HEAD
[1.9.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.8.0...v1.9.0
[1.8.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.7.3...v1.8.0
[1.7.3]: https://github.com/devuni-cz/notifier-agent/compare/v1.7.2...v1.7.3
[1.7.2]: https://github.com/devuni-cz/notifier-agent/compare/v1.7.1...v1.7.2
[1.7.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.7.0...v1.7.1
[1.7.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.6.2...v1.7.0
[1.6.2]: https://github.com/devuni-cz/notifier-agent/compare/v1.6.1...v1.6.2
[1.6.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.6.0...v1.6.1
[1.6.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.5.0...v1.6.0
[1.5.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.4.1...v1.5.0
[1.4.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/devuni-cz/notifier-agent/releases/tag/v1.0.0
