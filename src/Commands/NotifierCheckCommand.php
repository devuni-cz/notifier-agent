<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Services\Database\LazyDatabaseDumper;
use Devuni\Notifier\Services\Database\MysqlDumper;
use Devuni\Notifier\Services\Database\PostgresDumper;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\Zip\CliZipCreator;
use Devuni\Notifier\Services\Zip\PhpZipCreator;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

final class NotifierCheckCommand extends Command
{
    use DisplayHelperTrait;
    use RendersReportTrait;

    protected $signature = 'notifier:check';

    protected $description = 'Verify the Notifier agent configuration and server connectivity';

    public function handle(NotifierConfigService $configService, NotifierLoggerService $notifierLogger): int
    {
        $this->displayNotifierHeader('Health Check');

        $this->checkEnvironmentVariables($configService);
        $this->checkDatabaseConnection();
        $this->checkStorageDirectories();
        $this->checkDatabaseDumpTool();
        $this->checkZipAvailability();
        $this->checkLoggingChannel($notifierLogger);
        $this->checkQueueConfiguration();
        $this->checkBackupUrlReachability();

        return $this->renderReportSummary(
            'All checks passed! Notifier agent is ready to use.',
            'Ready to use, but some checks need attention.',
            'Some checks failed. Please fix the issues above.',
        );
    }

    /**
     * Check if all required environment variables are set.
     */
    private function checkEnvironmentVariables(NotifierConfigService $configService): void
    {
        $this->section('environment variables');

        $missing = $configService->checkEnvironment();

        if (empty($missing)) {
            $this->passLine('All required environment variables are configured');
            $this->showConfiguredValues();
            $this->record('Environment variables', self::STATUS_PASS);

            return;
        }

        $this->failLine('Missing environment variables:');
        foreach ($missing as $variable) {
            $this->line("        <fg=red>•</> {$variable}");
        }
        $this->hint('Run: php artisan notifier:install');
        $this->record('Environment variables', self::STATUS_FAIL);
    }

    /**
     * Show configured values (masked for security).
     */
    private function showConfiguredValues(): void
    {
        $this->detail('NOTIFIER_BACKUP_CODE', $this->maskValue(config('notifier.backup_code')));
        $this->detail('NOTIFIER_URL', '<fg=cyan>'.(config('notifier.backup_url') ?: '(empty)').'</>');
        $this->detail('NOTIFIER_BACKUP_PASSWORD', $this->maskValue(config('notifier.backup_zip_password')));
    }

    /**
     * Check database connection.
     */
    private function checkDatabaseConnection(): void
    {
        $this->section('database connection');

        try {
            DB::connection()->getPdo();
            $databaseName = DB::connection()->getDatabaseName();
            $this->passLine("Connected to database: <fg=cyan>{$databaseName}</>");
            $this->record('Database connection', self::STATUS_PASS);
        } catch (Throwable $e) {
            $this->failLine('Database connection failed');
            $this->hint("Error: {$e->getMessage()}");
            $this->record('Database connection', self::STATUS_FAIL);
        }
    }

    /**
     * Check if storage directories exist and are writable.
     */
    private function checkStorageDirectories(): void
    {
        $this->section('storage directories');

        $directories = [
            'Backup directory' => storage_path('app/private'),
            'Public storage' => storage_path('app/public'),
        ];

        $status = self::STATUS_PASS;

        foreach ($directories as $name => $path) {
            if (! File::isDirectory($path)) {
                $this->warnLine("{$name} does not exist: <fg=cyan>{$path}</>");
                $this->hint('Will be created automatically during backup');
                $status = $this->worst($status, self::STATUS_WARN);

                continue;
            }

            if (is_writable($path)) {
                $this->passLine("{$name}: <fg=cyan>{$path}</>");

                continue;
            }

            $this->failLine("{$name} is not writable: <fg=cyan>{$path}</>");
            $this->hint('Grant write access to the web user (chown / chmod) for this path');
            $status = self::STATUS_FAIL;
        }

        $this->record('Storage directories', $status);
    }

    /**
     * Check if the right dump binary is available for the configured driver.
     */
    private function checkDatabaseDumpTool(): void
    {
        $this->section('database dump tool');

        $connection = config('notifier.database_connection') ?: config('database.default');
        $driver = config("database.connections.{$connection}.driver");

        $this->detail('Connection', "<fg=cyan>{$connection}</> <fg=gray>(driver: {$driver})</>");

        try {
            $dumper = app(DatabaseDumperInterface::class);

            // Unwrap LazyDatabaseDumper to expose the concrete implementation
            if ($dumper instanceof LazyDatabaseDumper) {
                $dumper = $dumper->resolve();
            }
        } catch (Throwable $e) {
            $this->failLine($e->getMessage());
            $this->record('Database dump tool', self::STATUS_FAIL);

            return;
        }

        $available = match (true) {
            $dumper instanceof MysqlDumper => MysqlDumper::isAvailable(),
            $dumper instanceof PostgresDumper => PostgresDumper::isAvailable(),
            default => false,
        };

        if ($available) {
            $this->passLine($dumper->describe());
            $this->record('Database dump tool', self::STATUS_PASS);

            return;
        }

        $hint = match (true) {
            $dumper instanceof MysqlDumper => 'Install MySQL client tools (e.g. `apt install mysql-client` or `mariadb-client`)',
            $dumper instanceof PostgresDumper => 'Install PostgreSQL client tools (`apt install postgresql-client`) or YugabyteDB tools',
            default => 'No dump tool available for this driver',
        };
        $this->failLine('Required dump binary is not available on PATH');
        $this->hint($hint);
        $this->record('Database dump tool', self::STATUS_FAIL);
    }

    private function checkZipAvailability(): void
    {
        $this->section('ZIP archive tools');

        $strategy = config('notifier.zip_strategy', 'auto');
        $cliAvailable = CliZipCreator::isAvailable();
        $phpAvailable = PhpZipCreator::isAvailable();

        $status = self::STATUS_PASS;

        if ($cliAvailable) {
            $this->passLine('CLI 7z is available (recommended for production)');
        } else {
            $this->warnLine('CLI 7z is not installed');
            $this->hint('Install: sudo apt install p7zip-full');
            $status = $this->worst($status, self::STATUS_WARN);
        }

        if ($phpAvailable) {
            $this->passLine('PHP ZIP extension is loaded (fallback)');
        } else {
            $this->warnLine('PHP ZIP extension is not loaded');
            $status = $this->worst($status, self::STATUS_WARN);
        }

        if (! $cliAvailable && ! $phpAvailable) {
            $this->failLine('No ZIP strategy available - storage backups will fail');
            $status = self::STATUS_FAIL;
        } else {
            $active = $cliAvailable ? 'cli (7z)' : 'php (ZipArchive)';

            if ($strategy !== 'auto') {
                $active = $strategy;
            }

            $this->detail('Active strategy', "<fg=cyan>{$active}</> <fg=gray>(config: {$strategy})</>");
        }

        $this->record('ZIP archive tools', $status);
    }

    /**
     * Check if the preferred logging channel is configured.
     */
    private function checkLoggingChannel(NotifierLoggerService $notifierLogger): void
    {
        $this->section('logging channel');

        $preferredChannel = $notifierLogger->getPreferredChannel();

        if ($notifierLogger->isUsingPreferredChannel()) {
            $this->passLine("Logging channel '<fg=cyan>{$preferredChannel}</>' is configured");
            $this->record('Logging channel', self::STATUS_PASS);

            return;
        }

        $this->warnLine("Logging channel '<fg=cyan>{$preferredChannel}</>' not found, using '<fg=cyan>daily</>' fallback");
        $this->hint('Add the channel to config/logging.php for dedicated backup logs');
        $this->record('Logging channel', self::STATUS_WARN);
    }

    private function checkQueueConfiguration(): void
    {
        $this->section('queue configuration');

        $connection = config('notifier.queue_connection', 'sync');

        if ($connection === 'sync') {
            $this->infoLine('Queue connection is "sync" - backups run synchronously in the HTTP request');
            $this->hint('Set NOTIFIER_QUEUE_CONNECTION to database, redis, or another async driver to offload backups');
            $this->record('Queue configuration', self::STATUS_WARN);

            return;
        }

        $this->passLine("Backups dispatched to queue: <fg=cyan>{$connection}</>");
        $this->record('Queue configuration', self::STATUS_PASS);
    }

    private function checkBackupUrlReachability(): void
    {
        $this->section('backup URL reachability');

        $backupUrl = config('notifier.backup_url');

        if (empty($backupUrl)) {
            $this->warnLine('Backup URL is not configured, skipping connectivity check');
            $this->record('Backup URL reachability', self::STATUS_WARN);

            return;
        }

        if (! str_starts_with($backupUrl, 'https://')) {
            $this->failLine("Backup URL must use HTTPS: <fg=cyan>{$backupUrl}</>");
            $this->record('Backup URL reachability', self::STATUS_FAIL);

            return;
        }

        try {
            $parsedUrl = parse_url($backupUrl);
            $baseUrl = ($parsedUrl['scheme'] ?? 'https').'://'.($parsedUrl['host'] ?? '');

            if (! empty($parsedUrl['port'])) {
                $baseUrl .= ':'.$parsedUrl['port'];
            }

            $response = Http::timeout(5)
                ->connectTimeout(5)
                ->head($baseUrl);

            $statusCode = $response->status();

            if ($statusCode < 500) {
                $this->passLine("The Notifier server is reachable: <fg=cyan>{$baseUrl}</>");
                $this->detail('Response status', (string) $statusCode);
                $this->record('Backup URL reachability', self::STATUS_PASS);
            } else {
                $this->failLine("The Notifier server returned an error: {$statusCode}");
                $this->record('Backup URL reachability', self::STATUS_FAIL);
            }
        } catch (Throwable $e) {
            $this->failLine('Cannot reach the Notifier server');
            $this->hint("Error: {$e->getMessage()}");
            $this->record('Backup URL reachability', self::STATUS_FAIL);
        }
    }
}
