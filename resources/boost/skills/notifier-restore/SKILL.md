---
name: notifier-restore
description: >
  Restore this Laravel app's database or storage from its Devuni Notifier backups with devuni/notifier-agent,
  or bring up a new deployment of an existing site from them. Use when the user asks to restore or recover data,
  roll back to a backup, refresh staging from backups, or set up a new server for an existing site.
compatibility: Requires devuni/notifier-agent with NOTIFIER_URL, NOTIFIER_BACKUP_PASSWORD and NOTIFIER_RESTORE_TOKEN set.
---

## Rules

- A restore overwrites live data. Run one only when the user asked for it, and confirm which environment.
- Always start with `--list` and `--dry-run`.
- Never add `--force`, `--no-snapshot` or `--accept-unverified` yourself. Show the user the command and let them decide.

## Restore the database

1. List the backups:
   ```bash
   php artisan notifier:database-restore --list
   ```
2. Check the chosen backup without changing anything:
   ```bash
   php artisan notifier:database-restore --id=N --dry-run
   ```
3. Restore it. The command asks for the database name and dumps the current database first:
   ```bash
   php artisan notifier:database-restore --id=N
   ```

## Restore storage

- Same steps with `notifier:storage-restore`.
- It adds and overwrites files in `storage/app/public`. It never deletes.
- It refuses a backup that contains `.php`, `.htaccess` or other executable or server config files.

## When the restore is refused

- "does not match NOTIFIER_BACKUP_PASSWORD": check first that `.env` has the original password. Offer `--accept-unverified` only if it does.
- "has no seal", "does not match its seal", "sealed for a different repository" or "cannot be authenticated": the message ends with `--id=N --accept-unverified=<sha256>` for that file. Ask the user before running it.
- "sealed on ... more than 35 days ago": the newest backup is old. Pass `--id` if it is the one the user wants.
- An approved unsealed storage backup goes to `storage/app/notifier-restore/`. The user copies files from there by hand.
- "a sealed backup of a different type": the server lists this backup under the wrong type. Stop and tell the user. The other restore command won't find it.
- No `NOTIFIER_RESTORE_TOKEN`: restore is disabled. The user gets the token from the control plane.

## New deployment

1. `.env` needs the original `NOTIFIER_BACKUP_PASSWORD`, the same `NOTIFIER_URL` and a `NOTIFIER_RESTORE_TOKEN`. A new password can't decrypt old backups.
2. Run `php artisan notifier:check`.
3. Restore the database, then storage, as above. Production needs `--force`; let the user add it.
4. Start backups only after the restore. Otherwise a backup of the empty database becomes the newest one.

## After a failed database restore

- MySQL and MariaDB: the command puts the database back from its safety dump.
- PostgreSQL: `psql` rolls back. If that can't be confirmed, the safety dump stays in `storage/app/notifier-restore/`.
