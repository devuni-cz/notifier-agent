# Changelog

All notable changes to `devuni/notifier-agent` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.4.1] - 2026-06-15

### Added

-   **Configurable cap on rendered announcement banners** - `notifier.announcements.max_visible` (env `NOTIFIER_ANNOUNCEMENTS_MAX_VISIBLE`, default 5). Each render location (the Filament render-hook banner and the `<x-notifier-announcements-notice />` Blade component) shows the **top-N priority-ordered** announcements plus a muted **`+ N dalších oznámení`** overflow line; `0` = unlimited. SPA/custom hosts via `AnnouncementsService::customAnnouncements()` still receive **all** announcements and decide their own display.

### Changed

-   **Announcement validity-window separator is now a plain hyphen `-`** instead of an en-dash `–` (avoids mojibake/odd rendering).

## [1.4.0] - 2026-06-15

### Added

-   **Validity-window line in the announcement banner.** Each announcement now renders a muted sub-line showing the period it applies - **`Platí: {od} – {do}`** when the server sends both bounds, or **`Platí od {od} (do odvolání)`** when it is open-ended. The window comes from the wire `starts_at` / `ends_at`; `published_at` is only the publish gate and is never shown. Times are converted to the **host app timezone** (`config('app.timezone')`) and formatted **absolutely** (`j. n. Y H:i`, e.g. `13. 6. 2026 23:12`) so the value is safe to cache for the 15-minute announcement TTL without going stale. The label is derived once at fetch time (`validity_label`, fail-soft - a malformed date is logged and the line is simply omitted) and renders in both the Filament render-hook banner and the `<x-notifier-announcements-notice />` Blade component. Announcements without a start date render exactly as before.

## [1.3.0] - 2026-06-14

### Added

-   **Heartbeat / identity manifest.** A new `notifier:heartbeat` command pushes a periodic liveness + identity manifest to the control plane (`POST {NOTIFIER_URL}/heartbeat`, per-repository, same `X-Notifier-Token`), so the server can mark a site **stale after 6 h** of silence. **On by default**; the host app schedules it (recommended `Schedule::command('notifier:heartbeat')->hourly()->onOneServer()`). Disable with `NOTIFIER_HEARTBEAT_ENABLED=false` (the push becomes a no-op).
    -   The manifest reports the **agent / PHP / Laravel versions**, the **queue connection**, the **enabled feature** map (announcements / backups / heartbeat), **free + total disk bytes** on the storage volume, the **last database & storage backup times**, and the agent's own clock (`reported_at`). The server stamps its own receipt time - the agent never sends it.
    -   Unlike the fail-open announcements pull, the heartbeat is a **push**: a rejected or unreachable server is logged to the `backup` channel and makes the command exit non-zero, so a host scheduler's failure handling can react.
-   **Last-backup timestamps recorded.** `notifier:database-backup` and `notifier:storage-backup` now record the time of each successful backup (cache keys `notifier.last_database_backup_at` / `notifier.last_storage_backup_at`), which the heartbeat manifest reports to the control plane.

## [1.2.0] - 2026-06-14

### Added

-   **Per-announcement placement.** Each announcement now carries a `dashboard_type` (`filament` | `custom`, default `filament`) and an optional `target`, so the control plane decides where each notice lands instead of one global spot.
    -   **Filament hosts** route each announcement to the render hook named in its `target`; a null `target` falls to the default `render_hook`. List every position you want to support in the new `render_hooks` config (env `NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS`, comma-separated) - the default hook is always included, and each hook renders only the announcements that resolve to it.
    -   **Custom (SPA) hosts** get a new `AnnouncementsService::customAnnouncements(?string $target = null)` helper that returns only `dashboard_type=custom` announcements (optionally for a single `target` element id), so Inertia/Vue/React hosts can mount each notice at its own DOM element. Same fail-open robustness as `activeAnnouncements()`.
-   **Placement keys normalized** on every fetched announcement: `dashboard_type` defaults to `filament` and `target` to `null`, so older servers and existing consumers are fully backward compatible - out of the box (single `render_hook`, no `target`) every announcement still renders at the default hook exactly as before. `custom` announcements are never injected into Filament panels.

## [1.1.0] - 2026-06-11

### Added

-   **Announcement types.** The control plane can now classify announcements (`maintenance` / `outage` / `release` / `notice`); typed announcements render with a small chip label (Údržba / Výpadek / Novinka) in both the Filament banner and the Blade component, plus a `notifier-announcement--type-{type}` CSS class. `notice`, unknown types, and payloads from older servers render exactly as before - fully backward compatible.
-   **`data-announcement-id` attribute** on each rendered announcement (when the server sends `id`) - groundwork for client-side dismissal/deduplication later.

## [1.0.2] - 2026-06-11

### Security

-   **Failed token attempts on the backup endpoint are now rate-limited.** The throttle middleware ran *after* token verification, so invalid-token requests were never throttled and the `X-Notifier-Token` could be brute-forced at an unlimited rate. Throttling now runs first; the existing limit (10/hour) is unchanged.
-   **The backup endpoint no longer discloses configuration before authentication.** A missing token, an invalid token and a misconfigured server all return the same generic `403` body - previously the endpoint distinguished `401` vs `403` and a misconfigured install returned `500` **including the names of the missing `NOTIFIER_*` env variables**. The real reason is now logged server-side (with caller IP) instead.
-   **Plaintext database dump lifecycle hardened.** The `.sql` dump is `chmod 0600` immediately after creation (no-op on Windows), and is deleted in a `finally` even when ZIP creation throws - previously a failing ZIP step left the unencrypted dump on disk indefinitely.
-   **`notifier:install` now escapes values written to `.env`.** Values are double-quoted with backslash/double-quote escaping, so secrets containing quotes, spaces or backslashes can no longer corrupt the file (the replace-on-`--force` path also switched to `preg_replace_callback` so escaped values survive idempotent re-runs).

### Notes

-   Behavioral change for *unauthenticated/misconfigured* callers only: previous `401`/`500` responses are now a uniform `403`. Legitimate (valid-token) requests are unaffected.

## [1.0.1] - 2026-06-10

### Added

-   **`conflict` against `devuni/notifier-package`.** Both packages share the `Devuni\Notifier\` namespace and provider, so installing them side by side would corrupt class resolution silently. Composer now refuses the combination with an explicit error - migrate with `composer remove devuni/notifier-package` first (see README → *Migrating from devuni/notifier-package*).
-   **README migration guide** from `devuni/notifier-package` (one-step swap, version-numbering note, announcements opt-out hint).

### Changed

-   **`NotifierApiClient` now sends `Accept: application/json`**, so server-side `abort()` errors come back as compact JSON instead of HTML error pages ending up in the logs.
-   **Announcements `failure_cache_ttl` default raised 60 s → 300 s** - a down or not-yet-deployed server endpoint is retried (and logged) at most once per 5 minutes per site instead of once per minute.

### Tests

-   New transport test pinning the **redirects-disabled invariant** on token-bearing requests (mutation-verified: removing `allow_redirects => false` fails the test), plus an `Accept: application/json` assertion.
-   Announcement fixtures now use a real server severity value (`high`); the previous `warning` is not a value the server can send (`critical/high/medium/low/info`).

## [1.0.0] - 2026-06-10

The first official release of **`devuni/notifier-agent`** - the client agent of the [Devuni Notifier](https://notifier.devuni.cz) control plane, installed in each Laravel app. It pushes telemetry to the platform (encrypted backups) and pulls instructions from it (announcements), with more capabilities to come.

### Added

-   **Encrypted database & storage backups.** `notifier:database-backup` / `notifier:storage-backup` create AES-256 encrypted ZIPs (7z CLI or PHP zip) and ship them to the central server via a chunked HTTPS upload protocol (init → chunks with per-chunk SHA-256 + retry → finalize with async status polling). Supports **MySQL, MariaDB, PostgreSQL and YugabyteDB**; table/file exclusions; queue offloading for HTTP-triggered backups (`NOTIFIER_QUEUE_CONNECTION`).
-   **Announcements.** The agent pulls this site's active maintenance/announcement notices from the central server (`GET {NOTIFIER_URL}/announcements`, per-repository, cached, fail-open - a down server can never break the host dashboard). **On by default**; costs nothing until `NOTIFIER_URL` is configured.
    -   **Filament auto-injection** - on hosts running Filament (v3/v4/v5) the notices auto-inject as a styled banner into every panel page via a render hook (`NOTIFIER_ANNOUNCEMENTS_FILAMENT[_HOOK]`), registered only when Filament is installed.
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

[Unreleased]: https://github.com/devuni-cz/notifier-agent/compare/v1.4.1...HEAD
[1.4.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.4.0...v1.4.1
[1.4.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.3.0...v1.4.0
[1.3.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/devuni-cz/notifier-agent/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/devuni-cz/notifier-agent/releases/tag/v1.0.0
