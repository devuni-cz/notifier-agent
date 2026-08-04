<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Carbon\Carbon;
use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class NotifierStorageService
{
    public function __construct(
        private readonly ChunkedUploadService $uploadService,
        private readonly ZipCreatorInterface $zipCreator,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public function createStorageBackup(): string
    {
        $logger = $this->notifierLogger->get();

        $logger->info('⚙️ STARTING NEW BACKUP ⚙️');

        $backupDirectory = storage_path('app/private');
        File::ensureDirectoryExists($backupDirectory);

        // Type prefix + random per-run suffix: the prefix separates the two
        // backup types, the suffix keeps two same-type runs apart (the command,
        // sync-trigger and queue paths are not serialized against each other,
        // and the zip creator deletes a pre-existing target as "stale") - so
        // no two runs can ever share a path, even within the same second.
        $filename = 'backup-storage-'.Carbon::now()->format('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(4)).'.zip';
        $path = $backupDirectory.'/'.$filename;

        $logger->info('➡️ creating backup file');

        $sourcePath = storage_path('app/public');

        if (! File::isDirectory($sourcePath)) {
            throw new RuntimeException(
                'Storage source directory does not exist: '.$sourcePath
                .'. Make sure the storage directory is properly linked (php artisan storage:link)'
                .' and your deployment creates the correct symlinks for the shared storage folder.'
            );
        }

        $source = realpath($sourcePath);

        if ($source === false) {
            throw new RuntimeException(
                'Storage source directory could not be resolved: '.$sourcePath
                .'. This may indicate a broken symlink in your deployment setup.'
            );
        }

        $password = config('notifier.backup_zip_password');
        $excludedFiles = config('notifier.excluded_files', []);

        try {
            $fileCount = $this->zipCreator->create($source, $path, $password, $excludedFiles);
        } catch (RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'No files to backup')) {
                $logger->warning('⚠️ storage directory is empty, skipping backup', [
                    'source' => $source,
                ]);

                return '';
            }

            throw $e;
        }

        // A non-empty source that still produces a sub-threshold archive means
        // there is nothing meaningful to back up (a placeholder-only directory)
        // or the write was truncated. Treat it like an empty source (return '')
        // so the caller's "nothing to back up" skip handles it - never report a
        // successful backup or stamp the heartbeat for an archive we won't
        // send, and never ship an archive the control plane rejects as "Backup
        // too small" anyway (the server enforces the same 100 KiB floor).
        $size = filesize($path);
        $minBytes = $this->minBackupBytes();

        if ($size === false || $size < $minBytes) {
            $logger->warning('⚠️ backup archive is below the minimum size, skipping upload', [
                'file_size' => $size,
                'min_bytes' => $minBytes,
                'path' => $path,
            ]);

            File::delete($path);

            return '';
        }

        $logger->info("✅ backup archive created ({$fileCount} files): {$path}");

        return $path;
    }

    public function sendStorageBackup(string $path): void
    {
        $logger = $this->notifierLogger->get();

        $logger->info('➡️ preparing file for sending');

        $size = filesize($path);
        $minBytes = $this->minBackupBytes();

        if ($size === false || $size < $minBytes) {
            $logger->warning('⚠️ backup archive is below the minimum size, skipping upload', [
                'file_size' => $size,
                'min_bytes' => $minBytes,
                'path' => $path,
            ]);

            File::delete($path);
            $logger->info('➡️ backup file cleaned up');

            return;
        }

        try {
            $this->uploadService->upload($path, BackupTypeEnum::Storage->value);

            // Stamp the heartbeat timestamp here, at the one point every
            // successful upload passes through (artisan command, sync trigger
            // and queued job alike), so the manifest never reports a backup
            // that failed or was skipped. Best-effort: a cache-store hiccup
            // must not turn an upload that already succeeded into a failure.
            try {
                Cache::forever(HeartbeatService::LAST_STORAGE_BACKUP_KEY, now()->toIso8601String());
            } catch (Throwable $e) {
                $logger->warning('⚠️ could not record the last-backup timestamp for the heartbeat', [
                    'error' => $e->getMessage(),
                ]);
            }

            $logger->info('➡️ file was sent');
            $logger->info('✅ END OF BACKUP');
        } catch (Throwable $th) {
            $logger->error('❌ an error occurred while uploading a file', [
                'error' => $th->getMessage(),
                'file_size' => filesize($path),
                'php_file_upload_limit' => ini_get('upload_max_filesize'),
                'php_post_max_size' => ini_get('post_max_size'),
                'php_memory_limit' => ini_get('memory_limit'),
                'url' => config('notifier.backup_url'),
            ]);

            throw $th;
        } finally {
            File::delete($path);
            $logger->info('➡️ backup file cleaned up');
        }
    }

    /**
     * The agent-side floor mirroring the control plane's "Backup too small"
     * rejection threshold (102 400 B), overridable per site.
     */
    private function minBackupBytes(): int
    {
        return max(1, (int) config('notifier.min_storage_backup_bytes', 102400));
    }
}
