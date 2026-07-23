<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Authentication
    |--------------------------------------------------------------------------
    |
    | The backup code authenticates OUTBOUND requests this app makes to the
    | control plane (it is sent as X-Notifier-Token and verified there). The
    | server stores it hashed at rest.
    |
    | The trigger secret authenticates INBOUND server->client backup triggers
    | (the control plane presents it to this app's /api/notifier/backup). It is
    | a separate value so the backup code never has to be reversible on the
    | server. Falls back to the backup code for single-secret installs that
    | have not split the two yet.
    |
    */
    'backup_code' => env('NOTIFIER_BACKUP_CODE', env('BACKUP_CODE')),

    'trigger_secret' => env('NOTIFIER_TRIGGER_SECRET', env('NOTIFIER_BACKUP_CODE', env('BACKUP_CODE'))),

    /*
    |--------------------------------------------------------------------------
    | Restore Token
    |--------------------------------------------------------------------------
    |
    | Authenticates DOWNLOADS of this site's own backups from the control plane.
    | Deliberately a different credential from the backup code: leaking the code
    | that uploads backups must not also grant the ability to pull them back
    | down. Leave empty to make restore impossible for this install.
    |
    */
    'restore_token' => env('NOTIFIER_RESTORE_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Central Notifier URL
    |--------------------------------------------------------------------------
    |
    | The URL where backup files will be sent. This is the endpoint on
    | the central notifier.devuni.cz application.
    |
    */
    'backup_url' => env('NOTIFIER_URL', env('BACKUP_URL')),

    /*
    |--------------------------------------------------------------------------
    | Backup ZIP Password
    |--------------------------------------------------------------------------
    |
    | Password used to encrypt the storage backup ZIP files.
    | This should be a strong, unique password.
    |
    */
    'backup_zip_password' => env('NOTIFIER_BACKUP_PASSWORD', env('BACKUP_ZIP_PASSWORD')),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | Which Laravel database connection to back up. When null (default), the
    | package uses your app's default connection from config('database.default').
    |
    | Supported drivers: mysql, mariadb, pgsql (including YugabyteDB via YSQL).
    |
    */
    'database_connection' => env('NOTIFIER_DATABASE_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Dump Binary
    |--------------------------------------------------------------------------
    |
    | Which CLI binary to use for PostgreSQL/YugabyteDB dumps.
    |
    | Options:
    | - null (default) : Auto-detect - prefers `ysql_dump` (YugabyteDB), falls
    |                    back to `pg_dump` (standard PostgreSQL).
    | - 'pg_dump'      : Force standard PostgreSQL client.
    | - 'ysql_dump'    : Force YugabyteDB's ysql_dump (a pg_dump fork with
    |                    Yugabyte-specific optimizations).
    |
    | Only applies when the active connection driver is `pgsql`.
    |
    */
    'postgres_dump_binary' => env('NOTIFIER_POSTGRES_DUMP_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Restore Binary
    |--------------------------------------------------------------------------
    |
    | Which CLI client to use when RESTORING a PostgreSQL/YugabyteDB dump. The
    | restore-side mirror of `postgres_dump_binary`.
    |
    | Options:
    | - null (default) : Auto-detect - prefers `ysqlsh` (YugabyteDB), falls back
    |                    to `psql` (standard PostgreSQL).
    | - 'psql'         : Force standard PostgreSQL client.
    | - 'ysqlsh'       : Force YugabyteDB's ysqlsh (a psql fork; accepts every
    |                    flag the importer emits).
    |
    | Only applies when the active connection driver is `pgsql`.
    |
    */
    'postgres_restore_binary' => env('NOTIFIER_POSTGRES_RESTORE_BINARY'),

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Schema
    |--------------------------------------------------------------------------
    |
    | Default schema for unqualified table names in `excluded_tables` when
    | using a PostgreSQL/YugabyteDB connection. If a table name in the list
    | already contains a dot (e.g. "audit.events"), it is passed through
    | as-is.
    |
    */
    'postgres_schema' => env('NOTIFIER_POSTGRES_SCHEMA', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Excluded Database Tables
    |--------------------------------------------------------------------------
    |
    | Database tables that should be excluded from the database backup.
    | Useful for excluding large log tables or temporary data.
    |
    | Write plain table names - the package prefixes them automatically
    | (database name for MySQL/MariaDB, `postgres_schema` for PostgreSQL).
    | Fully qualified names containing a dot are passed through as-is.
    |
    | Examples:
    | - 'telescope_entries'      -> Laravel Telescope data
    | - 'telescope_entries_tags' -> Telescope relation table
    | - 'pulse_entries'          -> Laravel Pulse data
    | - 'sessions'               -> User sessions
    | - 'cache'                  -> Cache table
    | - 'audit.events'           -> Fully qualified - uses literal "audit" schema/db
    |
    */
    'excluded_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Excluded Files
    |--------------------------------------------------------------------------
    |
    | Files or directories that should be excluded from storage backup.
    | Paths are relative to storage/app/public.
    |
    | Examples:
    | - '.gitignore'        -> exclude .gitignore file
    | - 'temp'              -> exclude entire temp directory
    | - 'logs/debug.log'    -> exclude specific file
    |
    */
    'excluded_files' => [
        '.gitignore',
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | The logging channel used for backup operations.
    | Falls back to 'daily' if the specified channel doesn't exist.
    |
    */
    'logging_channel' => env('NOTIFIER_LOGGING_CHANNEL', 'backup'),

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | Control whether the package registers its API routes and
    | customize the route prefix.
    |
    | Set 'routes_enabled' to false if you want to define your
    | own routes using the package controller.
    |
    */
    'routes_enabled' => env('NOTIFIER_ROUTES_ENABLED', true),
    'route_prefix' => env('NOTIFIER_ROUTE_PREFIX', 'api/notifier'),

    /*
    |--------------------------------------------------------------------------
    | ZIP Strategy
    |--------------------------------------------------------------------------
    |
    | Controls how storage backup ZIP archives are created.
    |
    | Options:
    | - 'auto' (default) : Use CLI 7z if available, fall back to PHP ZipArchive
    | - 'cli'            : Force CLI 7z (requires p7zip-full package)
    | - 'php'            : Force PHP ZipArchive extension
    |
    | CLI 7z is recommended for production - it uses less memory,
    | handles large files better, and runs in a separate process.
    |
    */
    'zip_strategy' => env('NOTIFIER_ZIP_STRATEGY', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Restore Extraction Limits
    |--------------------------------------------------------------------------
    |
    | Guards against a decompression bomb / accidental disk-fill when extracting
    | a backup downloaded from the control plane. The archive is untrusted, so
    | its declared uncompressed size is checked BEFORE any byte is written.
    |
    | - restore_max_extracted_bytes : hard ceiling on the total uncompressed
    |   size of an archive. 0 disables the absolute cap. Default 10 GiB.
    | - restore_disk_free_margin_bytes : free space kept back on the destination
    |   volume; extraction is refused if it would consume more than
    |   (free space - margin). Default 256 MiB.
    | - restore_max_compression_ratio : reject an archive whose aggregate
    |   uncompressed/compressed ratio exceeds this. 0 disables the ratio guard
    |   (default), because legitimate SQL dumps compress very well.
    |
    */
    'restore_max_extracted_bytes' => (int) env('NOTIFIER_RESTORE_MAX_EXTRACTED_BYTES', 10 * 1024 * 1024 * 1024),
    'restore_disk_free_margin_bytes' => (int) env('NOTIFIER_RESTORE_DISK_FREE_MARGIN_BYTES', 256 * 1024 * 1024),
    'restore_max_compression_ratio' => (int) env('NOTIFIER_RESTORE_MAX_COMPRESSION_RATIO', 0),

    /*
    |--------------------------------------------------------------------------
    | Chunk Size
    |--------------------------------------------------------------------------
    |
    | The size of each chunk in bytes when uploading backup files.
    | Default is 20 MB.
    |
    | If you are using for example Cloudflare, you must stay under their upload limit (100 MB) of free plan.
    |
    */
    'chunk_size' => env('NOTIFIER_CHUNK_SIZE', 20 * 1024 * 1024),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | The queue connection used for backup jobs dispatched via the HTTP API.
    | When set to anything other than 'sync', backups are offloaded to a
    | queue worker - avoiding PHP max_execution_time limits.
    |
    | Supported: 'sync', 'database', 'redis', 'sqs', 'beanstalkd'
    |            (any connection defined in config/queue.php)
    |
    | 'sync'       - runs backup synchronously in the HTTP request (default)
    | 'database'   - dispatches to the jobs table (requires queue:table migration)
    | 'redis'      - dispatches to Redis (requires phpredis or predis)
    | 'sqs'        - dispatches to Amazon SQS
    | 'beanstalkd' - dispatches to Beanstalkd
    |
    | Artisan commands are not affected - they always run synchronously.
    |
    */
    'queue_connection' => env('NOTIFIER_QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Feature Toggles
    |--------------------------------------------------------------------------
    |
    | Switches for the agent's features. Announcements are ON by default but cost
    | nothing until NOTIFIER_URL is configured - the service no-ops and makes no
    | HTTP call without a target, so a backup-only install is unaffected.
    |
    */
    'features' => [
        // Whether this site runs the scheduled backups. Advertised in the
        // heartbeat manifest so the control plane knows which sites back up;
        // set NOTIFIER_BACKUPS_ENABLED=false on sites that intentionally don't.
        'backups' => env('NOTIFIER_BACKUPS_ENABLED', true),

        // Pull this site's maintenance/announcement notices from the central
        // server and expose them for rendering in your own dashboard.
        'announcements' => env('NOTIFIER_ANNOUNCEMENTS_ENABLED', true),

        // Push a periodic identity + liveness manifest (agent/PHP/Laravel
        // versions, disk space, last backup times) to the control plane via the
        // `notifier:heartbeat` command, so the server can mark a site stale when
        // it stops hearing from this agent. The host app schedules the command;
        // set NOTIFIER_HEARTBEAT_ENABLED=false to turn the push into a no-op.
        'heartbeat' => env('NOTIFIER_HEARTBEAT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Announcements
    |--------------------------------------------------------------------------
    |
    | When the `announcements` feature is enabled, the package fetches this site's
    | active announcements from `{NOTIFIER_URL}/announcements` (per-repository, authenticated
    | with the same X-Notifier-Token). Responses are cached so the consumer
    | dashboard never makes a blocking request on every page load.
    |
    */
    'announcements' => [
        // Seconds a successful response is cached. Default 15 minutes.
        'cache_ttl' => (int) env('NOTIFIER_ANNOUNCEMENTS_CACHE_TTL', 900),

        // Seconds to negative-cache an empty result after a fetch failure, so a
        // down/slow/not-yet-deployed server doesn't make every dashboard load
        // pay the timeout (or re-log a warning every minute).
        'failure_cache_ttl' => (int) env('NOTIFIER_ANNOUNCEMENTS_FAILURE_CACHE_TTL', 300),

        // HTTP timeout (seconds) for the announcements request.
        'timeout' => (int) env('NOTIFIER_ANNOUNCEMENTS_TIMEOUT', 5),

        // Caps how many announcement banners render at each location (the Filament
        // render-hook banner and the <x-notifier-announcements-notice /> Blade
        // component). The server returns ALL active announcements priority-ordered;
        // each render point shows only the top-N (most important first) plus a muted
        // "+ N dalších" overflow line, so a burst of notices can't bury the dashboard.
        // 0 = unlimited (render every announcement). Does NOT affect
        // AnnouncementsService::customAnnouncements() - SPA/custom hosts still get
        // the full list and decide their own display.
        'max_visible' => (int) env('NOTIFIER_ANNOUNCEMENTS_MAX_VISIBLE', 5),

        /*
        | Filament integration. When the host app uses Filament, the package can
        | auto-inject the active announcements as a banner into every panel page
        | via a Filament render hook - no manual placement needed. Registered only
        | when Filament is actually installed, so non-Filament hosts are unaffected.
        |
        | Placement model: each announcement carries a `dashboard_type`
        | ("filament" | "custom", default "filament") and an optional `target`.
        | A filament announcement is routed to the render hook named in its
        | `target`; when `target` is null it falls to the default `render_hook`
        | below. The package only injects at the hooks listed in `render_hooks`,
        | so a host opts a position in simply by listing it there. `custom`
        | announcements are NOT rendered here - they are for non-Filament (SPA)
        | hosts, which fetch them via AnnouncementsService::customAnnouncements().
        */
        'filament' => [
            // Auto-inject the announcements banner into Filament panels.
            'enabled' => env('NOTIFIER_ANNOUNCEMENTS_FILAMENT', true),

            // The default render hook for filament announcements with no `target`.
            // A plain string keeps this version-agnostic across Filament v3/v4/v5.
            // Default: top of the page content. Alternatives: 'panels::body.start',
            // 'panels::topbar.end'.
            'render_hook' => env('NOTIFIER_ANNOUNCEMENTS_FILAMENT_HOOK', 'panels::content.start'),

            // The full set of render hooks the package injects at. A targeted
            // filament announcement only appears if its hook is listed here, so a
            // host enables extra positions by adding them. The default `render_hook`
            // is always included so null-target announcements still have a home.
            // Set a comma-separated list to support multiple positions, e.g.
            // NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS="panels::content.start,panels::topbar.end".
            'render_hooks' => array_values(array_filter(array_map(
                'trim',
                explode(',', (string) env(
                    'NOTIFIER_ANNOUNCEMENTS_RENDER_HOOKS',
                    env('NOTIFIER_ANNOUNCEMENTS_FILAMENT_HOOK', 'panels::content.start'),
                )),
            ), static fn (string $hook): bool => $hook !== '')),
        ],
    ],
];
