<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Carbon\Carbon;
use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Services\Database\LazyDatabaseDumper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class NotifierDatabaseService
{
    public function __construct(
        private readonly ChunkedUploadService $uploadService,
        private readonly ZipCreatorInterface $zipCreator,
        private readonly DatabaseDumperInterface $databaseDumper,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public function createDatabaseBackup(): string
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
        $basename = 'backup-database-'.Carbon::now()->format('Y-m-d_H-i-s').'-'.bin2hex(random_bytes(4));
        $path = $backupDirectory.'/'.$basename.'.sql';

        // Log the concrete dumper, not the lazy proxy, so the entry distinguishes
        // MySQL vs PostgreSQL backups.
        $concreteDumper = $this->databaseDumper instanceof LazyDatabaseDumper
            ? $this->databaseDumper->resolve()
            : $this->databaseDumper;

        $logger->info('➡️ creating backup file', [
            'dumper' => $concreteDumper::class,
        ]);

        $this->databaseDumper->dump($path);

        // Validate the SQL dump before proceeding
        if (! file_exists($path)) {
            throw new RuntimeException(
                'SQL dump file was not created at: '.$path
                .'. Dump command reported success but the file does not exist.'
            );
        }

        // Restrict the plaintext dump to the owner only. Suppress errors:
        // chmod is a no-op on Windows and must never abort the backup.
        @chmod($path, 0o600);

        $dumpSize = filesize($path);

        if ($dumpSize === false || $dumpSize === 0) {
            File::delete($path);

            throw new RuntimeException(
                'SQL dump file is empty at: '.$path
                .'. The database may be empty or the dump command produced no output.'
            );
        }

        $logger->info('✅ SQL dump created', [
            'path' => $path,
            'size' => $dumpSize,
        ]);

        // Encrypt the SQL dump into a password-protected ZIP
        $password = config('notifier.backup_zip_password');

        if (! empty($password)) {
            $zipPath = $backupDirectory.'/'.$basename.'.zip';

            try {
                $this->zipCreator->create($path, $zipPath, $password, []);
            } finally {
                // Never leave the plaintext dump behind, even when ZIP
                // creation throws.
                File::delete($path);
                $logger->info('➡️ plaintext SQL dump cleaned up');
            }

            $logger->info('➡️ SQL dump encrypted into ZIP archive');

            return $zipPath;
        }

        return $path;
    }

    public function sendDatabaseBackup(string $path): void
    {
        $logger = $this->notifierLogger->get();

        $logger->info('➡️ preparing file for sending');

        try {
            $this->uploadService->upload($path, BackupTypeEnum::Database->value);

            // Stamp the heartbeat timestamp here, at the one point every
            // successful upload passes through (artisan command, sync trigger
            // and queued job alike), so the manifest never reports a backup
            // that failed or was skipped. Best-effort: a cache-store hiccup
            // must not turn an upload that already succeeded into a failure.
            try {
                Cache::forever(HeartbeatService::LAST_DATABASE_BACKUP_KEY, now()->toIso8601String());
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
}
