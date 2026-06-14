<?php

declare(strict_types=1);

namespace Devuni\Notifier\Tests\Filament;

use Devuni\Notifier\NotifierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Boots the package provider with the announcements feature + Filament
 * integration enabled and TWO configured render hooks
 * ('panels::content.start' default + 'panels::topbar.end'), so the per-target
 * routing in registerFilamentAnnouncements() can be exercised: each hook's
 * closure must render only the announcements that resolve to it.
 *
 * Not final: Pest dynamically subclasses the bound test case (see the
 * tests/TestCase.php precedent and pint.json `notPath`).
 */
class AnnouncementsMultiHookTestCase extends Orchestra
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [NotifierServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('notifier.features.announcements', true);
        $app['config']->set('notifier.announcements.filament.enabled', true);
        $app['config']->set('notifier.announcements.filament.render_hook', 'panels::content.start');
        $app['config']->set('notifier.announcements.filament.render_hooks', [
            'panels::content.start',
            'panels::topbar.end',
        ]);
        $app['config']->set('notifier.backup_url', 'https://notifier.devuni.cz/api/v1/repositories/52740614');
        $app['config']->set('notifier.backup_code', 'super-secret-token');
    }
}
