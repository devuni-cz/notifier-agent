<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Services\NotifierRestoreService;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class NotifierDatabaseRestoreCommand extends Command
{
    use DisplayHelperTrait;
    use RendersReportTrait;

    protected $signature = 'notifier:database-restore
        {--id= : Restore this specific backup id instead of the newest}
        {--list : Only list the available database backups and exit}
        {--dry-run : Download and verify the archive, but do not touch the database}
        {--force : Skip the interactive confirmation (required in production)}
        {--no-snapshot : Do not dump the current database before overwriting it}';

    protected $description = 'Download the latest database backup from the Notifier server and restore it';

    public function handle(NotifierRestoreService $restore, DatabaseDumperInterface $dumper): int
    {
        $this->displayNotifierHeader('Database Restore');

        try {
            if ($this->option('list')) {
                return $this->listBackups($restore);
            }

            $backup = $restore->resolve(BackupTypeEnum::Database, $this->intOption('id'));
        } catch (Throwable $e) {
            $this->failLine($e->getMessage());

            return self::FAILURE;
        }

        $this->section('Selected backup');
        $this->infoLine('#'.$backup['id'].'  '.$backup['name'].'  ('.$this->humanBytes($backup['size']).', '.($backup['created_at'] ?? 'unknown date').')');

        // A dry run never touches the database, so it must not be blocked by the
        // production gate (which exists to guard the destructive path) nor prompt.
        if (! $this->option('dry-run') && ! $this->confirmDestruction()) {
            return self::FAILURE;
        }

        $workDirectory = storage_path('app/notifier-restore/'.$backup['id']);
        $archivePath = $workDirectory.'/archive.zip';

        try {
            $this->section('Download');
            $restore->download($backup['id'], $archivePath, $backup['checksum'] ?? null);
            $this->passLine('Archive downloaded');

            $this->section('Extract');
            $extracted = $restore->extract($archivePath, $workDirectory.'/extracted');
            $this->passLine('Archive extracted');

            if ($this->option('dry-run')) {
                $this->warnLine('Dry run - the database was NOT modified.');

                return self::SUCCESS;
            }

            $snapshot = null;

            if (! $this->option('no-snapshot')) {
                $this->section('Safety snapshot');
                $snapshot = $this->takeSnapshot($dumper);
                $this->passLine('Current database dumped to '.$snapshot);
            }

            $this->section('Import');

            try {
                $restore->importDatabase($extracted);
            } catch (Throwable $importError) {
                // MySQL cannot roll a full-schema restore back (every DDL statement
                // implicitly commits), so a mid-import failure leaves the database
                // half-applied. The snapshot we just took IS the atomicity
                // guarantee - replay it. With --no-snapshot the operator accepted
                // that risk explicitly.
                if ($snapshot !== null) {
                    $this->rollbackFromSnapshot($restore, $snapshot);
                } else {
                    $this->warnLine('No snapshot was taken (--no-snapshot); the database may be left partially restored.');
                }

                throw $importError;
            }

            $this->passLine('Database restored from backup #'.$backup['id']);

            // The snapshot is a full plaintext copy of the database - never leave
            // it on disk once the restore has succeeded.
            if ($snapshot !== null) {
                File::delete($snapshot);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->failLine($e->getMessage());

            return self::FAILURE;
        } finally {
            // The extracted dump is a full plaintext copy of the database - never
            // leave it lying in storage/ after the run.
            File::deleteDirectory($workDirectory);
        }
    }

    private function listBackups(NotifierRestoreService $restore): int
    {
        $backups = $restore->available(BackupTypeEnum::Database);

        if ($backups === []) {
            $this->warnLine('The server has no database backup for this site.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Name', 'Size', 'Created'],
            array_map(fn (array $b): array => [
                $b['id'],
                $b['name'],
                $this->humanBytes($b['size']),
                $b['created_at'] ?? '-',
            ], $backups),
        );

        return self::SUCCESS;
    }

    /**
     * A restore overwrites live data, so production requires an explicit --force
     * and every interactive run must be confirmed by typing the database name.
     */
    private function confirmDestruction(): bool
    {
        $database = (string) config('database.connections.'.$this->connectionName().'.database');

        if ($this->option('force')) {
            return true;
        }

        if (app()->environment('production')) {
            $this->failLine('Refusing to restore in production without --force.');

            return false;
        }

        $this->warnLine('This will OVERWRITE the current contents of database "'.$database.'".');

        $answer = (string) $this->ask('Type the database name to confirm');

        if ($answer !== $database) {
            $this->failLine('Confirmation did not match - aborting.');

            return false;
        }

        return true;
    }

    private function takeSnapshot(DatabaseDumperInterface $dumper): string
    {
        $path = storage_path('app/notifier-restore/pre-restore-'.date('Y-m-d_H-i-s').'.sql');

        File::ensureDirectoryExists(dirname($path));

        // Lock the file to the owner BEFORE the dumper writes into it. mysqldump
        // (--result-file) / pg_dump (--file) open this existing file for writing
        // and keep its 0600 mode, so even a partial dump is never world-readable.
        // If we chmod only after dump() returns, a dump that throws mid-write
        // (lock/timeout, remote host drop, concurrent DDL) would leave a
        // world-readable plaintext DB copy behind. (@ - chmod/touch no-op on Windows.)
        touch($path);
        @chmod($path, 0600);

        try {
            $dumper->dump($path);
        } catch (Throwable $e) {
            // Never orphan a partial plaintext dump: $snapshot never gets assigned
            // when this throws, so no later cleanup path would ever remove it.
            @unlink($path);

            throw $e;
        }

        // Re-assert 0600 in case the dumper recreated the file under the umask
        // rather than truncating it in place.
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Best-effort recovery after a failed import: re-import the pre-restore
     * snapshot. On success the snapshot is deleted; on failure it is KEPT - it is
     * now the only copy of the pre-restore data - and its path is reported so the
     * operator can restore it by hand.
     */
    private function rollbackFromSnapshot(NotifierRestoreService $restore, string $snapshot): void
    {
        $this->warnLine('Import failed - rolling the database back to the pre-restore snapshot.');

        try {
            $restore->restoreDatabaseFromFile($snapshot);
            File::delete($snapshot);
            $this->passLine('Database rolled back to its pre-restore state.');
        } catch (Throwable $rollbackError) {
            $this->failLine(
                'ROLLBACK FAILED - the database may be inconsistent. Pre-restore snapshot kept at: '
                .$snapshot.' (restore it manually). Cause: '.$rollbackError->getMessage()
            );
        }
    }

    private function connectionName(): string
    {
        return (string) (config('notifier.database_connection') ?: config('database.default'));
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null ? null : (int) $value;
    }

    private function humanBytes(?int $bytes): string
    {
        if ($bytes === null) {
            return 'unknown size';
        }

        $units = ['B', 'KB', 'MB', 'GB'];
        $index = 0;
        $size = (float) $bytes;

        while ($size >= 1024 && $index < count($units) - 1) {
            $size /= 1024;
            $index++;
        }

        return round($size, 1).' '.$units[$index];
    }
}
