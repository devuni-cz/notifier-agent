<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Traits\ChecksNotifierEnvironmentTrait;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Devuni\Notifier\Traits\RunsBackupTrait;
use Illuminate\Console\Command;

final class NotifierDatabaseBackupCommand extends Command
{
    use ChecksNotifierEnvironmentTrait;
    use DisplayHelperTrait;
    use RendersReportTrait;
    use RunsBackupTrait;

    protected $signature = 'notifier:database-backup';

    protected $description = 'Dump the database and upload an encrypted backup to the Notifier server';

    public function handle(NotifierConfigService $configService, NotifierDatabaseService $databaseService): int
    {
        $this->displayNotifierHeader('Database Backup');

        if ($this->checkMissingVariables($configService) === self::FAILURE) {
            return self::FAILURE;
        }

        return $this->runBackup(
            'Database backup',
            fn (): string => $databaseService->createDatabaseBackup(),
            function (string $path) use ($databaseService): void {
                $databaseService->sendDatabaseBackup($path);
            },
            HeartbeatService::LAST_DATABASE_BACKUP_KEY,
        );
    }
}
