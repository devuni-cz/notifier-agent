# Changelog

All notable changes to `devuni/notifier-agent` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-06-10

### Added

-   **`conflict` against `devuni/notifier-package`.** Both packages share the `Devuni\Notifier\` namespace and provider, so installing them side by side would corrupt class resolution silently. Composer now refuses the combination with an explicit error — migrate with `composer remove devuni/notifier-package` first (see README → *Migrating from devuni/notifier-package*).
-   **README migration guide** from `devuni/notifier-package` (one-step swap, version-numbering note, announcements opt-out hint).

### Changed

-   **`NotifierApiClient` now sends `Accept: application/json`**, so server-side `abort()` errors come back as compact JSON instead of HTML error pages ending up in the logs.
-   **Announcements `failure_cache_ttl` default raised 60 s → 300 s** — a down or not-yet-deployed server endpoint is retried (and logged) at most once per 5 minutes per site instead of once per minute.

### Tests

-   New transport test pinning the **redirects-disabled invariant** on token-bearing requests (mutation-verified: removing `allow_redirects => false` fails the test), plus an `Accept: application/json` assertion.
-   Announcement fixtures now use a real server severity value (`high`); the previous `warning` is not a value the server can send (`critical/high/medium/low/info`).

## [1.0.0] - 2026-06-10

The first official release of **`devuni/notifier-agent`** — the client agent of the [Devuni Notifier](https://notifier.devuni.cz) control plane, installed in each Laravel app. It pushes telemetry to the platform (encrypted backups) and pulls instructions from it (announcements), with more capabilities to come.

### Added

-   **Encrypted database & storage backups.** `notifier:database-backup` / `notifier:storage-backup` create AES-256 encrypted ZIPs (7z CLI or PHP zip) and ship them to the central server via a chunked HTTPS upload protocol (init → chunks with per-chunk SHA-256 + retry → finalize with async status polling). Supports **MySQL, MariaDB, PostgreSQL and YugabyteDB**; table/file exclusions; queue offloading for HTTP-triggered backups (`NOTIFIER_QUEUE_CONNECTION`).
-   **Announcements.** The agent pulls this site's active maintenance/announcement notices from the central server (`GET {NOTIFIER_URL}/announcements`, per-repository, cached, fail-open — a down server can never break the host dashboard). **On by default**; costs nothing until `NOTIFIER_URL` is configured.
    -   **Filament auto-injection** — on hosts running Filament (v3/v4/v5) the notices auto-inject as a styled banner into every panel page via a render hook (`NOTIFIER_ANNOUNCEMENTS_FILAMENT[_HOOK]`), registered only when Filament is installed.
    -   **`<x-notifier-announcements-notice />`** Blade component for Blade hosts; Inertia/Vue/React hosts render `AnnouncementsService::activeAnnouncements()` themselves.
-   **Unified `NotifierApiClient` transport.** Every request to the control plane goes through a single client that enforces the security invariants in one place: **HTTPS-only** (the `X-Notifier-Token` secret is never sent over cleartext), **redirects disabled** on token-bearing requests, uniform auth header, base-URL resolution and JSON error formatting. New agent capabilities inherit these guarantees automatically.
-   **Setup & diagnostics.** `notifier:install` (interactive `.env` wizard) and `notifier:check` (validates env, database, dump binary, zip strategy, queue config and server reachability).
-   **HTTP trigger endpoint.** `POST {prefix}/backup` (default `api/notifier/backup`, token-authenticated via `hash_equals`, rate-limited) so the central server or an external scheduler can trigger backups remotely.

### Security

-   ZIP passwords are passed via stdin (never argv), archives are created with `0600` permissions and deleted after upload.
-   Per-chunk and whole-file SHA-256 verification; server-supplied `status_url` is origin-validated before the token is attached; server-supplied failure reasons are sanitized before logging.

### Notes

-   The PHP namespace is **`Devuni\Notifier\`** and the env surface uses the established `NOTIFIER_*` keys.
-   Built on the codebase previously published as `devuni/notifier-package` (2.x). That package is superseded by this one: its `v2.8.0` is the terminal release and all further development happens here. Migration is a one-step `composer remove devuni/notifier-package && composer require devuni/notifier-agent`.

[Unreleased]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.1...HEAD
[1.0.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/devuni-cz/notifier-agent/releases/tag/v1.0.0
