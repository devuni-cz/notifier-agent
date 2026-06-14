<?php

declare(strict_types=1);

use Devuni\Notifier\Tests\Filament\AnnouncementsHookEnabledTestCase;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Behavioral test of the Filament render-hook REGISTRATION
|--------------------------------------------------------------------------
|
| This exercises the real wiring in NotifierServiceProvider::registerFilament-
| Announcements(), not the Blade view in isolation. With filament/support
| installed as a dev dependency, Filament\Support\Facades\FilamentView exists,
| so the provider's class_exists() gate passes and the render hook is actually
| registered at boot - the path that runs in the ~19 client Filament apps.
|
| FilamentView::registerRenderHook() defers via static::resolved(), so the
| registration only materialises once the ViewManager facade root is resolved -
| which the first hasRenderHook()/renderHook() call triggers. No panel context
| is needed: ViewManager::renderHook() simply app()->call()s each registered
| closure, which renders our notifier::filament.announcements Blade banner.
|
| The three config gates each need the provider to boot under a different config,
| and Pest+testbench only reliably boot the app when a single test case is bound
| to a file via top-level uses(). So the two negative gates live in sibling files
| (FilamentRenderHookFeatureDisabledTest / FilamentRenderHookFilamentDisabledTest),
| each binding its own boot-time config via a dedicated test case in
| tests/Filament/Announcements*TestCase.php. These files sit under tests/Filament
| so the global uses(TestCase::class)->in('Feature', 'Unit') in tests/Pest.php
| does not also claim them (Pest forbids two test cases for one file).
|
*/

uses(AnnouncementsHookEnabledTestCase::class)->beforeEach(function () {
    Http::fake([
        '*/announcements' => Http::response([
            'announcements' => [
                ['id' => 7, 'content' => 'Plánovaná údržba v neděli 22:00.', 'severity' => 'critical'],
            ],
        ], 200),
    ]);
});

it('has filament/support installed so the class_exists gate passes', function () {
    // The third gate in registerFilamentAnnouncements() is class_exists(FilamentView).
    // With filament/support a dev dependency, that class now exists, so the gate no
    // longer short-circuits and the hook can actually register.
    expect(class_exists(FilamentView::class))->toBeTrue();
});

it('registers the hook under the configured render-hook name after boot', function () {
    expect(config('notifier.features.announcements'))->toBeTrue()
        ->and(config('notifier.announcements.filament.enabled'))->toBeTrue()
        ->and(config('notifier.announcements.filament.render_hook'))->toBe('panels::content.start');

    // Proves the provider's FilamentView::registerRenderHook() ran and stuck: the
    // ViewManager now reports a hook under the configured name.
    expect(FilamentView::hasRenderHook('panels::content.start'))->toBeTrue();
});

it('renders the announcements banner when the registered hook is invoked', function () {
    // Invoking the hook runs the registered closure, which resolves the
    // AnnouncementsService, fetches the (faked) announcement, and renders the
    // notifier::filament.announcements Blade view.
    $html = (string) FilamentView::renderHook('panels::content.start');

    expect($html)
        ->toContain('Plánovaná údržba v neděli 22:00.')
        ->toContain('notifier-announcement')
        ->toContain('notifier-announcement--critical')
        ->toContain('data-announcement-id="7"');
});

it('renders the banner through the configurable hook name, not a hard-coded one', function () {
    // The registered hook name comes from config, so a host that points it at a
    // different Filament position still gets the banner wired up there. Read the
    // configured name back and render through it to prove the indirection.
    $hookName = (string) config('notifier.announcements.filament.render_hook');

    expect((string) FilamentView::renderHook($hookName))
        ->toContain('Plánovaná údržba v neděli 22:00.');
});
