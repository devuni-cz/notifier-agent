<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Traits\ChecksNotifierEnvironmentTrait;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class NotifierDatabaseBackupCommand extends Command
{
    use ChecksNotifierEnvironmentTrait;
    use DisplayHelperTrait;

    protected $signature = 'notifier:database-backup';

    protected $description = 'Command for creating a database backup';

    public function handle(NotifierConfigService $configService, NotifierDatabaseService $databaseService): int
    {
        $this->displayNotifierHeader('Database Backup');

        if ($this->checkMissingVariables($configService) === self::FAILURE) {
            return self::FAILURE;
        }

        $this->line('⚙️  STARTING NEW BACKUP ⚙️');
        $this->newLine();

        $backup_path = $databaseService->createDatabaseBackup();
        $this->line('✅ Backup file created successfully at: '.$backup_path);
        $databaseService->sendDatabaseBackup($backup_path);

        // Record the success so the heartbeat manifest can report this site's
        // last database backup time to the control plane.
        Cache::forever(HeartbeatService::LAST_DATABASE_BACKUP_KEY, now()->toIso8601String());

        $this->newLine();
        $this->line('✅ End of backup');

        return self::SUCCESS;
    }
}
