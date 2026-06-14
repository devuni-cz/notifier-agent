<?php

declare(strict_types=1);

use Devuni\Notifier\NotifierServiceProvider;

/**
 * Unit test for the pure, private-static placement filter
 * NotifierServiceProvider::filamentAnnouncementsForHook(). It decides which
 * announcements render at a given Filament render hook and is the heart of the
 * per-announcement placement feature, so it is exercised in isolation here.
 */
function filamentAnnouncementsForHook(array $all, string $hook, string $defaultHook): array
{
    $method = new ReflectionMethod(NotifierServiceProvider::class, 'filamentAnnouncementsForHook');

    /** @var list<array<string, mixed>> $result */
    $result = $method->invoke(null, $all, $hook, $defaultHook);

    return $result;
}

it('keeps a null-target filament announcement only at the default hook', function () {
    $all = [['id' => 1, 'content' => 'a', 'dashboard_type' => 'filament', 'target' => null]];

    expect(filamentAnnouncementsForHook($all, 'panels::content.start', 'panels::content.start'))->toHaveCount(1)
        ->and(filamentAnnouncementsForHook($all, 'panels::topbar.end', 'panels::content.start'))->toBe([]);
});

it('routes a targeted filament announcement to its target hook only', function () {
    $all = [['id' => 2, 'content' => 'b', 'dashboard_type' => 'filament', 'target' => 'panels::topbar.end']];

    expect(filamentAnnouncementsForHook($all, 'panels::topbar.end', 'panels::content.start'))->toHaveCount(1)
        ->and(filamentAnnouncementsForHook($all, 'panels::content.start', 'panels::content.start'))->toBe([]);
});

it('treats a missing or empty dashboard_type as filament (back-compat)', function () {
    $all = [
        ['id' => 3, 'content' => 'no type key'],
        ['id' => 4, 'content' => 'empty type', 'dashboard_type' => ''],
    ];

    expect(filamentAnnouncementsForHook($all, 'panels::content.start', 'panels::content.start'))->toHaveCount(2);
});

it('excludes custom announcements from every hook', function () {
    $all = [['id' => 5, 'content' => 'c', 'dashboard_type' => 'custom', 'target' => 'panels::content.start']];

    expect(filamentAnnouncementsForHook($all, 'panels::content.start', 'panels::content.start'))->toBe([])
        ->and(filamentAnnouncementsForHook($all, 'panels::topbar.end', 'panels::content.start'))->toBe([]);
});

it('treats an empty-string target as null and falls to the default hook', function () {
    $all = [['id' => 6, 'content' => 'd', 'dashboard_type' => 'filament', 'target' => '   ']];

    expect(filamentAnnouncementsForHook($all, 'panels::content.start', 'panels::content.start'))->toHaveCount(1)
        ->and(filamentAnnouncementsForHook($all, 'panels::topbar.end', 'panels::content.start'))->toBe([]);
});

it('reindexes the filtered result as a list', function () {
    $all = [
        ['id' => 7, 'content' => 'keep', 'target' => 'panels::content.start'],
        ['id' => 8, 'content' => 'drop', 'target' => 'panels::topbar.end'],
        ['id' => 9, 'content' => 'keep2', 'target' => 'panels::content.start'],
    ];

    $result = filamentAnnouncementsForHook($all, 'panels::content.start', 'panels::content.start');

    expect(array_keys($result))->toBe([0, 1]);
});
