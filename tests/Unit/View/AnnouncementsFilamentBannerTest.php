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

    it('renders a type chip for typed announcements', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [
                ['content' => 'V neděli 22:00 odstávka.', 'severity' => 'high', 'type' => 'maintenance'],
            ],
        ])->render();

        expect($rendered)
            ->toContain('Údržba')
            ->toContain('notifier-announcement--type-maintenance')
            ->toContain('notifier-announcement__type');
    });

    it('renders no chip for the notice type, unknown types, or payloads without a type', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [
                ['content' => 'Plain notice.', 'severity' => 'info', 'type' => 'notice'],
                ['content' => 'Unknown type.', 'severity' => 'info', 'type' => 'party'],
                ['content' => 'Old server payload.', 'severity' => 'info'],
            ],
        ])->render();

        // The chip CLASS always exists in the inline <style>; what must not
        // render without a known, labelled type is the chip ELEMENT itself.
        expect($rendered)->not->toContain('<span class="notifier-announcement__type">');
    });

    it('exposes the announcement id as a data attribute when present', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [
                ['id' => 42, 'content' => 'With id.', 'severity' => 'info'],
                ['content' => 'Without id.', 'severity' => 'info'],
            ],
        ])->render();

        expect($rendered)->toContain('data-announcement-id="42"')
            ->and(mb_substr_count($rendered, 'data-announcement-id'))->toBe(1);
    });
});

it('enables the announcements feature by default', function () {
    expect(config('notifier.features.announcements'))->toBeTrue()
        ->and(config('notifier.announcements.filament.enabled'))->toBeTrue()
        ->and(config('notifier.announcements.filament.render_hook'))->toBe('panels::content.start');
});
