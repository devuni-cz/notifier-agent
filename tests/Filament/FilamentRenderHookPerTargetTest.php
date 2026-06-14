<?php

declare(strict_types=1);

use Devuni\Notifier\Tests\Filament\AnnouncementsMultiHookTestCase;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Per-announcement placement across multiple Filament render hooks
|--------------------------------------------------------------------------
|
| With render_hooks = ['panels::content.start' (default), 'panels::topbar.end'],
| registerFilamentAnnouncements() wires up BOTH hooks. Each hook's closure must
| render only the announcements that resolve to it: a filament announcement is
| routed to its `target` hook, a null `target` falls to the default hook, and a
| `custom` announcement is rendered at NO filament hook (it's for SPA hosts).
|
| This binds AnnouncementsMultiHookTestCase (its own file, per the split-file
| pattern explained in FilamentRenderHookTest) and fakes one announcement per
| placement so each assertion can prove a hook shows exactly the right subset.
|
*/

uses(AnnouncementsMultiHookTestCase::class)->beforeEach(function () {
    Http::fake([
        '*/announcements' => Http::response([
            'announcements' => [
                // null target → falls to the default hook (panels::content.start)
                ['id' => 1, 'content' => 'Default hook notice.', 'severity' => 'info'],
                // explicit default target
                ['id' => 2, 'content' => 'Explicit content-start notice.', 'severity' => 'high', 'target' => 'panels::content.start'],
                // targeted at the topbar hook
                ['id' => 3, 'content' => 'Topbar notice.', 'severity' => 'critical', 'target' => 'panels::topbar.end'],
                // custom dashboard_type → not a filament announcement at all
                ['id' => 4, 'content' => 'SPA-only notice.', 'severity' => 'info', 'dashboard_type' => 'custom', 'target' => 'spa-banner'],
            ],
        ], 200),
    ]);
});

it('registers every configured render hook after boot', function () {
    expect(FilamentView::hasRenderHook('panels::content.start'))->toBeTrue()
        ->and(FilamentView::hasRenderHook('panels::topbar.end'))->toBeTrue();
});

it('renders null-target and default-target filament announcements at the default hook', function () {
    $html = (string) FilamentView::renderHook('panels::content.start');

    expect($html)
        ->toContain('Default hook notice.')
        ->toContain('Explicit content-start notice.')
        // the topbar-targeted and the custom announcement must NOT appear here
        ->not->toContain('Topbar notice.')
        ->not->toContain('SPA-only notice.');
});

it('renders a targeted filament announcement only at its target hook', function () {
    $html = (string) FilamentView::renderHook('panels::topbar.end');

    expect($html)
        ->toContain('Topbar notice.')
        ->not->toContain('Default hook notice.')
        ->not->toContain('Explicit content-start notice.')
        ->not->toContain('SPA-only notice.');
});

it('never renders a custom (SPA) announcement at any filament hook', function () {
    $contentStart = (string) FilamentView::renderHook('panels::content.start');
    $topbar = (string) FilamentView::renderHook('panels::topbar.end');

    expect($contentStart)->not->toContain('SPA-only notice.')
        ->and($topbar)->not->toContain('SPA-only notice.');
});
