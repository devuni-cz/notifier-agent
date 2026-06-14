<?php

declare(strict_types=1);

use Devuni\Notifier\Tests\Filament\AnnouncementsFeatureDisabledTestCase;
use Filament\Support\Facades\FilamentView;

/*
|--------------------------------------------------------------------------
| GATE 1 - announcements feature disabled
|--------------------------------------------------------------------------
|
| With notifier.features.announcements=false, registerFilamentAnnouncements()
| must return before touching FilamentView, so NO render hook is registered -
| even though filament/support is installed and the Filament integration flag
| is left on (proving THIS gate is what stops it). See FilamentRenderHookTest
| for the enabled path and the shared explanation of the testbench setup.
|
*/

uses(AnnouncementsFeatureDisabledTestCase::class);

it('does not register the hook when the announcements feature is off', function () {
    expect(class_exists(FilamentView::class))->toBeTrue()
        ->and(config('notifier.features.announcements'))->toBeFalse()
        ->and(config('notifier.announcements.filament.enabled'))->toBeTrue();

    expect(FilamentView::hasRenderHook('panels::content.start'))->toBeFalse();
    expect((string) FilamentView::renderHook('panels::content.start'))->toBe('');
});
