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

        if (! $this->confirmDestruction()) {
            return self::FAILURE;
        }

        $workDirectory = storage_path('app/notifier-restore/'.$backup['id']);
        $archivePath = $workDirectory.'/archive.zip';

        try {
            $this->section('Download');
            $restore->download($backup['id'], $archivePath);
            $this->passLine('Archive downloaded');

            $this->section('Extract');
            $extracted = $restore->extract($archivePath, $workDirectory.'/extracted');
            $this->passLine('Archive extracted');

            if ($this->option('dry-run')) {
                $this->warnLine('Dry run - the database was NOT modified.');

                return self::SUCCESS;
            }

            if (! $this->option('no-snapshot')) {
                $this->section('Safety snapshot');
                $snapshot = $this->takeSnapshot($dumper);
                $this->passLine('Current database dumped to '.$snapshot);
            }

            $this->section('Import');
            $restore->importDatabase($extracted);
            $this->passLine('Database restored from backup #'.$backup['id']);

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
        $dumper->dump($path);

        return $path;
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
