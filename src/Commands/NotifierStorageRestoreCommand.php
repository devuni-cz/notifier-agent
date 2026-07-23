<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Services\NotifierRestoreService;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class NotifierStorageRestoreCommand extends Command
{
    use DisplayHelperTrait;
    use RendersReportTrait;

    protected $signature = 'notifier:storage-restore
        {--id= : Restore this specific backup id instead of the newest}
        {--list : Only list the available storage backups and exit}
        {--dry-run : Download and verify the archive, but do not write any files}
        {--force : Skip the interactive confirmation (required in production)}';

    protected $description = 'Download the latest storage backup from the Notifier server and restore it';

    public function handle(NotifierRestoreService $restore): int
    {
        $this->displayNotifierHeader('Storage Restore');

        try {
            if ($this->option('list')) {
                return $this->listBackups($restore);
            }

            $backup = $restore->resolve(BackupTypeEnum::Storage, $this->intOption('id'));
        } catch (Throwable $e) {
            $this->failLine($e->getMessage());

            return self::FAILURE;
        }

        $storagePath = storage_path('app/public');

        $this->section('Selected backup');
        $this->infoLine('#'.$backup['id'].'  '.$backup['name'].'  ('.$this->humanBytes($backup['size']).', '.($backup['created_at'] ?? 'unknown date').')');
        $this->infoLine('Target: '.$storagePath);

        // A dry run never writes to storage, so it must not be blocked by the
        // production gate (which guards the destructive path) nor prompt.
        if (! $this->option('dry-run') && ! $this->confirmRestore($storagePath)) {
            return self::FAILURE;
        }

        $workDirectory = storage_path('app/notifier-restore/storage-'.$backup['id']);
        $archivePath = $workDirectory.'/archive.zip';

        try {
            $this->section('Download');
            $restore->download($backup['id'], $archivePath, $backup['checksum'] ?? null);
            $this->passLine('Archive downloaded');

            $this->section('Extract');
            $extracted = $restore->extract($archivePath, $workDirectory.'/extracted');
            $this->passLine('Archive extracted');

            if ($this->option('dry-run')) {
                $this->warnLine('Dry run - no files were written to storage.');

                return self::SUCCESS;
            }

            $this->section('Restore');
            $written = $restore->restoreStorage($extracted, $storagePath);
            $this->passLine($written.' file(s) restored from backup #'.$backup['id']);
            $this->infoLine('Existing files were overwritten; nothing was deleted.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->failLine($e->getMessage());

            return self::FAILURE;
        } finally {
            File::deleteDirectory($workDirectory);
        }
    }

    private function listBackups(NotifierRestoreService $restore): int
    {
        $backups = $restore->available(BackupTypeEnum::Storage);

        if ($backups === []) {
            $this->warnLine('The server has no storage backup for this site.');

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

    private function confirmRestore(string $storagePath): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (app()->environment('production')) {
            $this->failLine('Refusing to restore in production without --force.');

            return false;
        }

        $this->warnLine('Files in "'.$storagePath.'" with the same paths will be OVERWRITTEN.');

        return $this->confirm('Continue?', false);
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
