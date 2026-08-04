<?php

declare(strict_types=1);

namespace Devuni\Notifier\Jobs;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\NotifierStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

final class ProcessBackupJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * Release the uniqueness lock after 900s so a crashed worker cannot wedge
     * the type permanently (matches the job timeout).
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly BackupTypeEnum $backupType,
    ) {}

    /**
     * One in-flight backup per type: a flood of triggers on a queued connection
     * collapses to a single queued/running job per type instead of an unbounded
     * backlog (the queue-path counterpart to the sync-path lock in the trigger
     * controller).
     */
    public function uniqueId(): string
    {
        return 'notifier-backup:'.$this->backupType->value;
    }

    public function handle(
        NotifierConfigService $configService,
        NotifierDatabaseService $databaseService,
        NotifierStorageService $storageService,
        NotifierLoggerService $notifierLogger,
    ): void {
        $logger = $notifierLogger->get();
        $startTime = microtime(true);

        // The trigger route's middleware already verifies the environment, but
        // this job can also be dispatched directly from host code, and the env
        // can change between dispatch and a queued run. Re-check here so a
        // missing NOTIFIER_BACKUP_PASSWORD can never produce an unencrypted
        // upload (the command path refuses to run for the same reason).
        $missing = $configService->checkEnvironment();

        if ($missing !== []) {
            throw new RuntimeException(
                'Backup aborted - missing environment variables: '.implode(', ', $missing)
            );
        }

        $logger->info('🚀 backup job started', [
            'backup_type' => $this->backupType->value,
        ]);

        match ($this->backupType) {
            BackupTypeEnum::Database => $this->backupDatabase($databaseService),
            BackupTypeEnum::Storage => $this->backupStorage($storageService),
        };

        $duration = round(microtime(true) - $startTime, 2);

        $logger->info('✅ backup job completed', [
            'backup_type' => $this->backupType->value,
            'duration_seconds' => $duration,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $notifierLogger = app(NotifierLoggerService::class);
        $notifierLogger->get()->error('❌ backup job failed', [
            'backup_type' => $this->backupType->value,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    private function backupDatabase(NotifierDatabaseService $service): void
    {
        $path = $service->createDatabaseBackup();
        $service->sendDatabaseBackup($path);
    }

    private function backupStorage(NotifierStorageService $service): void
    {
        $path = $service->createStorageBackup();

        if ($path === '') {
            return;
        }

        $service->sendStorageBackup($path);
    }
}
