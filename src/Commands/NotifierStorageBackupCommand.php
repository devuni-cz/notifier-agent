<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierStorageService;
use Devuni\Notifier\Traits\ChecksNotifierEnvironmentTrait;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Devuni\Notifier\Traits\RunsBackupTrait;
use Illuminate\Console\Command;

final class NotifierStorageBackupCommand extends Command
{
    use ChecksNotifierEnvironmentTrait;
    use DisplayHelperTrait;
    use RendersReportTrait;
    use RunsBackupTrait;

    protected $signature = 'notifier:storage-backup';

    protected $description = 'Archive the public storage and upload an encrypted backup to the Notifier server';

    public function handle(NotifierConfigService $configService, NotifierStorageService $storageService): int
    {
        $this->displayNotifierHeader('Storage Backup');

        if ($this->checkMissingVariables($configService) === self::FAILURE) {
            return self::FAILURE;
        }

        return $this->runBackup(
            'Storage backup',
            fn (): string => $storageService->createStorageBackup(),
            function (string $path) use ($storageService): void {
                $storageService->sendStorageBackup($path);
            },
            HeartbeatService::LAST_STORAGE_BACKUP_KEY,
        );
    }
}
