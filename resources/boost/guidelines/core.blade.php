# Devuni Notifier Agent

- `devuni/notifier-agent` backs up this app's database and `storage/app/public` to the Devuni Notifier control plane, restores them, sends a heartbeat and shows announcements.
- Settings are `NOTIFIER_*` keys in `.env` and the published `config/notifier.php`. Run `php artisan notifier:check` after changing them.

## Secrets

- Never print, log, commit or hard-code `NOTIFIER_BACKUP_CODE`, `NOTIFIER_BACKUP_PASSWORD`, `NOTIFIER_RESTORE_TOKEN` or `NOTIFIER_TRIGGER_SECRET`.
- Never change or regenerate `NOTIFIER_BACKUP_PASSWORD` on a site that has backups. Old backups can't be decrypted without it.
- Don't run `php artisan notifier:install --force` unless the user asks. It rewrites these keys.

## Scheduling

- Sites managed by the control plane: it triggers the backups. Don't schedule `notifier:database-backup` or `notifier:storage-backup`, or every backup runs twice.
- Self-scheduled sites only: schedule both backup commands with `->onOneServer()`.
- Always schedule `notifier:heartbeat` hourly with `->onOneServer()`.
- Keep `NOTIFIER_ROUTES_ENABLED` on where the control plane triggers backups.

## Restore

- `notifier:database-restore` and `notifier:storage-restore` overwrite live data. Run them only when the user asks, and start with `--dry-run`.
- Never add `--force`, `--no-snapshot` or `--accept-unverified` on your own. Show the command and let the user decide.
- Activate the `notifier-restore` skill for a restore or a new deployment.

## Exclusions

- `excluded_tables` and `excluded_files` are arrays in the published `config/notifier.php`.
- PostgreSQL: a restore drops and recreates `NOTIFIER_POSTGRES_SCHEMA`, so excluded tables are gone afterwards. Exclude both tables of a foreign key or neither.

## Announcements

- Filament: the banner is added to every panel page automatically. Don't add it by hand.
- Blade: `<x-notifier-announcements-notice />`.
- Inertia, Vue, React: pass `app(\Devuni\Notifier\Services\AnnouncementsService::class)->customAnnouncements()` to the page and render each item at its `target` element.
