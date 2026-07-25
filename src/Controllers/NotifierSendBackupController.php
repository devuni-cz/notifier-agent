<?php

declare(strict_types=1);

namespace Devuni\Notifier\Controllers;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Jobs\ProcessBackupJob;
use Devuni\Notifier\Requests\BackupRequest;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\NotifierStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

final class NotifierSendBackupController
{
    public function __construct(
        private readonly NotifierDatabaseService $databaseService,
        private readonly NotifierStorageService $storageService,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public function __invoke(BackupRequest $request): JsonResponse
    {
        $backupType = $request->backupType();

        if (config('notifier.queue_connection', 'sync') !== 'sync') {
            return $this->dispatchBackupJob($backupType);
        }

        // Concurrency guard: on the default 'sync' connection each trigger runs
        // the full dump+zip inline in the PHP-FPM worker. Without a lock, a caller
        // holding the trigger secret could flood this endpoint (the throttle is
        // per-IP and trivially rotated) and stack unbounded heavy backups, starving
        // the worker pool / CPU / disk and taking the site offline. One lock per
        // backup type: a db and a storage backup may still run at once, but a
        // second backup of the SAME type is refused (429) rather than piled on.
        // The 900s TTL auto-releases if the worker dies mid-backup.
        $lock = Cache::lock('notifier:backup-run:'.$backupType->value, 900);

        if (! $lock->get()) {
            $this->notifierLogger->get()->warning('⏳ backup trigger refused - a backup of this type is already running', [
                'backup_type' => $backupType->value,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'A backup of this type is already in progress.',
                'backup_type' => $backupType->value,
                'timestamp' => now()->toIso8601String(),
            ], 429);
        }

        try {
            return match ($backupType) {
                BackupTypeEnum::Database => $this->backupDatabase(),
                BackupTypeEnum::Storage => $this->backupStorage(),
            };
        } finally {
            $lock->release();
        }
    }

    private function dispatchBackupJob(BackupTypeEnum $backupType): JsonResponse
    {
        ProcessBackupJob::dispatch($backupType)
            ->onConnection(config('notifier.queue_connection'));

        $this->notifierLogger->get()->info('📨 backup job dispatched to queue', [
            'backup_type' => $backupType->value,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Backup job dispatched to queue.',
            'backup_type' => $backupType->value,
            'queued' => true,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function backupDatabase(): JsonResponse
    {
        try {
            $startTime = microtime(true);

            $backupPath = $this->databaseService->createDatabaseBackup();
            $this->databaseService->sendDatabaseBackup($backupPath);

            $duration = round(microtime(true) - $startTime, 2);

            return response()->json([
                'success' => true,
                'message' => 'Database backup completed successfully.',
                'backup_type' => 'database',
                'duration_seconds' => $duration,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            $errorId = (string) Str::uuid();

            $this->notifierLogger->get()->error('Database backup failed.', [
                'error_id' => $errorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Database backup failed. See server logs for details.',
                'backup_type' => 'database',
                'error_id' => $errorId,
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }

    private function backupStorage(): JsonResponse
    {
        try {
            $startTime = microtime(true);

            $backupPath = $this->storageService->createStorageBackup();
            $this->storageService->sendStorageBackup($backupPath);

            $duration = round(microtime(true) - $startTime, 2);

            return response()->json([
                'success' => true,
                'message' => 'Storage backup completed successfully.',
                'backup_type' => 'storage',
                'duration_seconds' => $duration,
                'timestamp' => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            $errorId = (string) Str::uuid();

            $this->notifierLogger->get()->error('Storage backup failed.', [
                'error_id' => $errorId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Storage backup failed. See server logs for details.',
                'backup_type' => 'storage',
                'error_id' => $errorId,
                'timestamp' => now()->toIso8601String(),
            ], 500);
        }
    }
}
