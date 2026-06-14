<?php

declare(strict_types=1);

namespace Devuni\Notifier;

use Devuni\Notifier\Commands\NotifierCheckCommand;
use Devuni\Notifier\Commands\NotifierDatabaseBackupCommand;
use Devuni\Notifier\Commands\NotifierHeartbeatCommand;
use Devuni\Notifier\Commands\NotifierInstallCommand;
use Devuni\Notifier\Commands\NotifierStorageBackupCommand;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Services\AnnouncementsService;
use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\Database\MysqlDumper;
use Devuni\Notifier\Services\Database\PostgresDumper;
use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierApiClient;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\NotifierStorageService;
use Devuni\Notifier\Services\Zip\CliZipCreator;
use Devuni\Notifier\Services\Zip\PhpZipCreator;
use Devuni\Notifier\View\Components\AnnouncementsNotice;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class NotifierServiceProvider extends ServiceProvider
{
    public static function basePath(string $path): string
    {
        return __DIR__.'/..'.$path;
    }

    public function register(): void
    {
        $this->mergeConfigFrom(self::basePath('/config/notifier.php'), 'notifier');

        $this->app->singleton(NotifierApiClient::class);
        $this->app->singleton(NotifierConfigService::class);
        $this->app->singleton(ChunkedUploadService::class);
        $this->app->singleton(NotifierDatabaseService::class);
        $this->app->singleton(NotifierStorageService::class);
        $this->app->singleton(AnnouncementsService::class);
        $this->app->singleton(HeartbeatService::class);

        // Bind lazily: the actual dumper is resolved on first use, so unsupported
        // drivers (e.g. sqlite in test envs) don't blow up at container resolution
        // time for code paths that don't actually dump the database.
        $this->app->singleton(DatabaseDumperInterface::class, fn ($app): DatabaseDumperInterface => new Services\Database\LazyDatabaseDumper(
            fn (): DatabaseDumperInterface => self::resolveDumper($app),
        ));

        $this->app->singleton(ZipCreatorInterface::class, function ($app): ZipCreatorInterface {
            $strategy = config('notifier.zip_strategy', 'auto');
            $logger = $app->make(NotifierLoggerService::class);

            return match ($strategy) {
                'cli' => CliZipCreator::isAvailable()
                    ? new CliZipCreator($logger)
                    : throw new RuntimeException('CLI zip strategy requested but 7z is not installed. Install p7zip-full.'),
                'php' => PhpZipCreator::isAvailable()
                    ? new PhpZipCreator($logger)
                    : throw new RuntimeException('PHP zip strategy requested but the zip extension is not loaded.'),
                default => match (true) {
                    CliZipCreator::isAvailable() => new CliZipCreator($logger),
                    PhpZipCreator::isAvailable() => new PhpZipCreator($logger),
                    default => throw new RuntimeException('No ZIP strategy available. Install 7z (p7zip-full) or enable the PHP zip extension.'),
                },
            };
        });

        $this->app->singleton(NotifierLoggerService::class, function (): NotifierLoggerService {
            $preferredChannel = config('notifier.logging_channel', 'backup');

            return new NotifierLoggerService($preferredChannel);
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::basePath('/config/notifier.php') => config_path('notifier.php'),
            ], 'notifier-config');

            $this->publishes([
                self::basePath('/resources/views') => resource_path('views/vendor/notifier'),
            ], 'notifier-views');

            $this->commands([
                NotifierCheckCommand::class,
                NotifierDatabaseBackupCommand::class,
                NotifierHeartbeatCommand::class,
                NotifierInstallCommand::class,
                NotifierStorageBackupCommand::class,
            ]);
        }

        // The <x-notifier-announcements-notice /> component is always available; the
        // AnnouncementsService itself gates behavior on the `features.announcements` flag, so a
        // disabled install renders nothing and makes no HTTP call.
        $this->loadViewsFrom(self::basePath('/resources/views'), 'notifier');
        $this->loadViewComponentsAs('notifier', [
            AnnouncementsNotice::class,
        ]);

        $this->registerFilamentAnnouncements();

        if (config('notifier.routes_enabled', true)) {
            $this->loadRoutesFrom(self::basePath('/routes/web.php'));
        }
    }

    /**
     * Resolve the concrete DatabaseDumperInterface implementation for the currently configured connection.
     *
     * @throws RuntimeException When the connection or driver is unsupported.
     */
    private static function resolveDumper(\Illuminate\Contracts\Foundation\Application $app): DatabaseDumperInterface
    {
        $logger = $app->make(NotifierLoggerService::class);

        $connection = config('notifier.database_connection') ?: config('database.default');

        if (empty($connection)) {
            throw new RuntimeException(
                'No database connection configured. Set NOTIFIER_DATABASE_CONNECTION or '
                .'configure a default Laravel database connection.'
            );
        }

        $driver = config("database.connections.{$connection}.driver");

        return match ($driver) {
            'mysql', 'mariadb' => new MysqlDumper($connection, $logger),
            'pgsql' => new PostgresDumper($connection, $logger),
            null => throw new RuntimeException(
                "Database connection '{$connection}' is not configured in config/database.php."
            ),
            default => throw new RuntimeException(
                "Unsupported database driver '{$driver}' for connection '{$connection}'. "
                .'Supported drivers: mysql, mariadb, pgsql.'
            ),
        };
    }

    /**
     * Filter a flat announcements list down to the ones that should render at a
     * given Filament render hook: filament announcements (a missing/empty
     * `dashboard_type` counts as filament for back-compat) whose effective target
     * - the trimmed `target`, or the default hook when null/empty - equals $hook.
     *
     * Pure and side-effect free so it can be unit-tested in isolation.
     *
     * @param  list<array<string, mixed>>  $all
     * @return list<array<string, mixed>>
     */
    private static function filamentAnnouncementsForHook(array $all, string $hook, string $defaultHook): array
    {
        return array_values(array_filter($all, static function (array $announcement) use ($hook, $defaultHook): bool {
            $dashboardType = mb_trim((string) ($announcement['dashboard_type'] ?? ''));

            if ($dashboardType !== '' && $dashboardType !== 'filament') {
                return false;
            }

            $target = mb_trim((string) ($announcement['target'] ?? ''));
            $effectiveTarget = $target !== '' ? $target : $defaultHook;

            return $effectiveTarget === $hook;
        }));
    }

    /**
     * Auto-inject the active announcements as a banner into Filament panels via
     * render hooks. No-ops unless the announcements feature is on, the Filament
     * integration is enabled, and Filament is actually installed in the host app -
     * so non-Filament hosts (and the package's own tests) are never touched.
     *
     * Each filament announcement is routed to its `target` render hook (or the
     * default `render_hook` when `target` is null); `custom` announcements are
     * skipped here - they belong to non-Filament (SPA) hosts. A hook is wired up
     * only when it appears in `render_hooks`, and its closure renders just the
     * announcements that resolve to that hook. The render hook is identified by a
     * plain string, which is stable across Filament v3/v4/v5, so no Filament class
     * is referenced at the type level.
     */
    private function registerFilamentAnnouncements(): void
    {
        if (! config('notifier.features.announcements', true)) {
            return;
        }

        if (! config('notifier.announcements.filament.enabled', true)) {
            return;
        }

        if (! class_exists(\Filament\Support\Facades\FilamentView::class)) {
            return;
        }

        $defaultHook = (string) config('notifier.announcements.filament.render_hook', 'panels::content.start');

        foreach ($this->filamentRenderHooks($defaultHook) as $hook) {
            \Filament\Support\Facades\FilamentView::registerRenderHook(
                $hook,
                function () use ($hook, $defaultHook): string {
                    $announcements = self::filamentAnnouncementsForHook(
                        $this->app->make(AnnouncementsService::class)->activeAnnouncements(),
                        $hook,
                        $defaultHook,
                    );

                    if ($announcements === []) {
                        return '';
                    }

                    return View::make('notifier::filament.announcements', [
                        'announcements' => $announcements,
                    ])->render();
                },
            );
        }
    }

    /**
     * The de-duplicated list of render hooks to wire up. Falls back to the single
     * default hook when nothing is configured, and always includes the default so
     * null-target filament announcements have a home.
     *
     * @return list<string>
     */
    private function filamentRenderHooks(string $defaultHook): array
    {
        $configured = config('notifier.announcements.filament.render_hooks', []);

        $hooks = [];

        if (is_array($configured)) {
            foreach ($configured as $hook) {
                $hook = mb_trim((string) $hook);

                if ($hook !== '') {
                    $hooks[] = $hook;
                }
            }
        }

        if ($hooks === []) {
            $hooks[] = $defaultHook;
        }

        if (! in_array($defaultHook, $hooks, true)) {
            $hooks[] = $defaultHook;
        }

        return array_values(array_unique($hooks));
    }
}
