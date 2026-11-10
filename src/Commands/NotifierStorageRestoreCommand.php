<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Services\NotifierRestoreService;
use Devuni\Notifier\Services\VerifiedArchive;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

final class NotifierStorageRestoreCommand extends Command
{
    use DisplayHelperTrait;
    use RendersReportTrait;

    protected $signature = 'notifier:storage-restore
        {--id= : Restore this specific backup id instead of the newest}
        {--list : Only list the available storage backups and exit}
        {--dry-run : Download and verify the archive, but do not write any files}
        {--force : Skip the interactive confirmation (required in production)}
        {--accept-unverified= : Extract one archive that cannot be authenticated to a private directory, named by the SHA-256 the refusal prints (needs --id)}';

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
            $this->failLine(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        }

        $storagePath = storage_path('app/public');

        $this->section('Selected backup');
        $this->infoLine('#'.$backup['id'].'  '.$backup['name'].'  ('.$this->humanBytes($backup['size']).', '.($backup['created_at'] ?? 'unknown date').')');
        $this->infoLine('Target: '.$storagePath);

        // A dry run never writes to storage, so it must not be blocked by the
        // production gate (which guards the destructive path) nor prompt.
        if (! $this->option('dry-run') && ! $this->allowedInThisEnvironment()) {
            return self::FAILURE;
        }

        $workDirectory = null;

        try {
            $workDirectory = $restore->workDirectory();
            $archivePath = $workDirectory.'/archive.zip';

            $this->section('Download');
            $restore->download($backup['id'], $archivePath, $backup['checksum'] ?? null);
            $this->passLine('Archive downloaded');

            $this->section('Verify');
            $archive = $restore->verify(BackupTypeEnum::Storage, $archivePath, $backup['id'], $this->option('id') !== null, $this->option('accept-unverified'));
            $this->reportProvenance($archive);

            // After verification, so what the operator confirms is authenticated.
            // An unverified restore overwrites nothing, so there is nothing to confirm.
            if (! $this->option('dry-run') && $archive->sealed && ! $this->confirmRestore($storagePath)) {
                return self::FAILURE;
            }

            $this->section('Extract');
            $extracted = $restore->extract($archive, $workDirectory.'/extracted');
            $this->passLine('Archive extracted');

            if ($this->option('dry-run')) {
                $restore->assertStorageEntriesSafe($extracted);
                $this->warnLine('Dry run - no files were written to storage.');

                return self::SUCCESS;
            }

            if (! $archive->sealed) {
                $this->section('Private copy');
                $location = $restore->quarantineStorage($extracted, $backup['id']);
                $this->warnLine('Nothing was written to '.$storagePath.'. The unverified files are in: '.$location);
                $this->infoLine('Review them and copy what you need. Delete this directory when you are done.');

                return self::SUCCESS;
            }

            $this->section('Restore');
            $written = $restore->restoreStorage($extracted, $storagePath);
            $this->passLine($written.' file(s) restored from backup #'.$backup['id']);
            $this->infoLine('Existing files were overwritten; nothing was deleted.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->failLine(OutputFormatter::escape($e->getMessage()));

            return self::FAILURE;
        } finally {
            if ($workDirectory !== null) {
                File::deleteDirectory($workDirectory);
            }
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

    /**
     * Production requires an explicit --force. Checked before the download, so a
     * refused run costs nothing.
     */
    private function allowedInThisEnvironment(): bool
    {
        if ($this->option('force') || ! app()->environment('production')) {
            return true;
        }

        $this->failLine('Refusing to restore in production without --force.');

        return false;
    }

    private function reportProvenance(VerifiedArchive $archive): void
    {
        if ($archive->sealed) {
            $this->passLine($archive->describe());
        } else {
            $this->warnLine($archive->describe());
        }

        if ($archive->foreignSite) {
            $this->warnLine('This archive was sealed for a different repository. It is being restored only because you approved this exact file.');
        }
    }

    private function confirmRestore(string $storagePath): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->warnLine('Files in "'.$storagePath.'" with the same paths will be OVERWRITTEN.');

        return $this->confirm('Continue?', false);
    }

    private function intOption(string $name): ?int
    {
        $value = $this->option($name);

        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($id === false) {
            throw new RuntimeException('--'.$name.' must be a positive whole number.');
        }

        return $id;
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
