<?php

declare(strict_types=1);

describe('notifier::filament.announcements', function () {
    it('renders a styled banner for each active announcement', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [
                ['content' => 'Plánovaná údržba v neděli 22:00.', 'severity' => 'critical'],
                ['content' => 'Drobná aktualizace.', 'severity' => 'info'],
            ],
        ])->render();

        expect($rendered)
            ->toContain('Plánovaná údržba v neděli 22:00.')
            ->toContain('notifier-announcement--critical')
            ->toContain('Drobná aktualizace.')
            ->toContain('notifier-announcement--info');
    });

    it('escapes the announcement content', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [['content' => '<script>alert(1)</script>', 'severity' => 'high']],
        ])->render();

        expect($rendered)
            ->not->toContain('<script>alert(1)</script>')
            ->toContain('&lt;script&gt;');
    });

    it('renders nothing when there are no announcements', function () {
        $rendered = mb_trim(view('notifier::filament.announcements', ['announcements' => []])->render());

        expect($rendered)->toBe('');
    });
});

it('enables the announcements feature by default', function () {
    expect(config('notifier.features.announcements'))->toBeTrue()
        ->and(config('notifier.announcements.filament.enabled'))->toBeTrue()
        ->and(config('notifier.announcements.filament.render_hook'))->toBe('panels::content.start');
});
