<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Carbon\Carbon;
use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Services\Database\LazyDatabaseDumper;
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

        $filename = 'backup-'.Carbon::now()->format('Y-m-d_H-i-s').'.sql';
        $path = $backupDirectory.'/'.$filename;

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
            $zipPath = $backupDirectory.'/backup-'.Carbon::now()->format('Y-m-d_H-i-s').'.zip';

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
