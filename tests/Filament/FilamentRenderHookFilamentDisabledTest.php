<?php

declare(strict_types=1);

use Devuni\Notifier\Tests\Filament\AnnouncementsFilamentDisabledTestCase;
use Filament\Support\Facades\FilamentView;

/*
|--------------------------------------------------------------------------
| GATE 2 - Filament integration disabled
|--------------------------------------------------------------------------
|
| The announcements feature is on, but notifier.announcements.filament.enabled
| is false, so registerFilamentAnnouncements() must return before registering
| the render hook. A host that wants the data but places the banner itself can
| switch off the auto-injection without losing announcements. See
| FilamentRenderHookTest for the enabled path and the testbench setup notes.
|
*/

uses(AnnouncementsFilamentDisabledTestCase::class);

it('does not register the hook when the filament integration is off', function () {
    expect(class_exists(FilamentView::class))->toBeTrue()
        ->and(config('notifier.features.announcements'))->toBeTrue()
        ->and(config('notifier.announcements.filament.enabled'))->toBeFalse();

    expect(FilamentView::hasRenderHook('panels::content.start'))->toBeFalse();
    expect((string) FilamentView::renderHook('panels::content.start'))->toBe('');
});
