<?php

declare(strict_types=1);

namespace Devuni\Notifier\Tests\Filament;

use Devuni\Notifier\NotifierServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * GATE 1: boots the provider with the announcements feature OFF (Filament
 * integration left on) so registerFilamentAnnouncements() must bail before
 * registering the hook.
 *
 * Not final: Pest dynamically subclasses the bound test case.
 */
class AnnouncementsFeatureDisabledTestCase extends Orchestra
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

        $app['config']->set('notifier.features.announcements', false);
        // Left enabled on purpose: the feature gate alone must stop registration.
        $app['config']->set('notifier.announcements.filament.enabled', true);
    }
}
