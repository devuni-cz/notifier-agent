<?php

declare(strict_types=1);

use Devuni\Notifier\Tests\Filament\AnnouncementsHookEnabledTestCase;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Validity-window line through the real Filament render hook
|--------------------------------------------------------------------------
|
| End-to-end proof that the derived validity_label (built in AnnouncementsService
| at fetch time) surfaces in the banner the render hook actually renders. Reuses
| the enabled-hook boot config (AnnouncementsHookEnabledTestCase) like
| FilamentRenderHookTest, but - unlike that file - keeps the Http::fake INSIDE
| each test rather than in a file-level beforeEach. Stub callbacks merge and the
| first match wins (Factory::fake), so a file-level fake could not be overridden
| per test; a body-level fake gives each test its own single stub for the
| announcements endpoint.
|
*/

uses(AnnouncementsHookEnabledTestCase::class);

it('renders the validity-window line in the banner when the server sends start/end dates', function () {
    config(['app.timezone' => 'Europe/Prague']);

    Http::fake([
        '*/announcements' => Http::response([
            'announcements' => [
                [
                    'id' => 7,
                    'content' => 'Plánovaná údržba v neděli 22:00.',
                    'severity' => 'critical',
                    'starts_at' => '2026-06-13T21:12:00+00:00',
                    'ends_at' => '2026-06-14T04:00:00+00:00',
                ],
            ],
        ], 200),
    ]);

    expect((string) FilamentView::renderHook('panels::content.start'))
        ->toContain('Plánovaná údržba v neděli 22:00.')
        ->toContain('<span class="notifier-announcement__validity">')
        ->toContain('Platí: 13. 6. 2026 23:12 - 14. 6. 2026 06:00');
});

it('omits the validity line in the banner when the announcement has no start date', function () {
    Http::fake([
        '*/announcements' => Http::response([
            'announcements' => [
                ['id' => 7, 'content' => 'Bez okna platnosti.', 'severity' => 'critical'],
            ],
        ], 200),
    ]);

    // The validity CSS rule always lives in the inline <style>; what must not
    // render without a start date is the sub-line ELEMENT itself.
    expect((string) FilamentView::renderHook('panels::content.start'))
        ->toContain('Bez okna platnosti.')
        ->not->toContain('<span class="notifier-announcement__validity">');
});
