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

    it('renders the validity-window sub-line when validity_label is present', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [
                [
                    'content' => 'Plánovaná údržba.',
                    'severity' => 'high',
                    'validity_label' => 'Platí: 13. 6. 2026 23:12 - 14. 6. 2026 06:00',
                ],
            ],
        ])->render();

        // Assert the sub-line ELEMENT, not the bare class (which always exists in
        // the inline <style> CSS rule).
        expect($rendered)
            ->toContain('<span class="notifier-announcement__validity">')
            ->toContain('Platí: 13. 6. 2026 23:12 - 14. 6. 2026 06:00');
    });

    it('omits the validity sub-line element when validity_label is null or absent', function () {
        $rendered = view('notifier::filament.announcements', [
            'announcements' => [
                ['content' => 'No window.', 'severity' => 'info', 'validity_label' => null],
                ['content' => 'No key at all.', 'severity' => 'info'],
            ],
        ])->render();

        // The CSS rule for the class always lives in the inline <style>; what must
        // not render without a validity_label is the sub-line ELEMENT itself.
        expect($rendered)->not->toContain('<span class="notifier-announcement__validity">');
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

    describe('render cap', function () {
        /**
         * @return list<array<string, mixed>>
         */
        function bannerAnnouncements(int $count): array
        {
            return array_map(
                static fn (int $i): array => ['content' => "Oznámení {$i}.", 'severity' => 'info'],
                range(1, $count),
            );
        }

        it('renders only the top-N (priority order) and a "+ N dalších" line when over the cap', function () {
            config(['notifier.announcements.max_visible' => 5]);

            $rendered = view('notifier::filament.announcements', [
                'announcements' => bannerAnnouncements(7),
            ])->render();

            // Exactly 5 banners render (the first 5, priority order preserved).
            expect(mb_substr_count($rendered, 'notifier-announcement notifier-announcement--'))->toBe(5);

            // The first 5 are present in order; the 6th and 7th are not.
            expect($rendered)
                ->toContain('Oznámení 1.')
                ->toContain('Oznámení 5.')
                ->not->toContain('Oznámení 6.')
                ->not->toContain('Oznámení 7.');

            // The overflow line summarises the 2 hidden announcements. Assert the
            // ELEMENT, not the bare class (which always lives in the inline <style>).
            expect($rendered)
                ->toContain('<div class="notifier-announcement__more">')
                ->toContain('+ 2 dalších oznámení');
        });

        it('renders everything and no overflow line when at or under the cap', function () {
            config(['notifier.announcements.max_visible' => 5]);

            $rendered = view('notifier::filament.announcements', [
                'announcements' => bannerAnnouncements(3),
            ])->render();

            expect(mb_substr_count($rendered, 'notifier-announcement notifier-announcement--'))->toBe(3);
            // The overflow ELEMENT must not render (the CSS class always lives in <style>).
            expect($rendered)
                ->toContain('Oznámení 3.')
                ->not->toContain('<div class="notifier-announcement__more">');
        });

        it('defaults the cap to 5 when the config key is absent', function () {
            // Drop the key entirely (rewrite the array without it) so the view's own
            // `config(..., 5)` default kicks in. Setting it to null would not - the
            // key would still exist and config() would return that null.
            $announcements = config('notifier.announcements');
            unset($announcements['max_visible']);
            config(['notifier.announcements' => $announcements]);

            $rendered = view('notifier::filament.announcements', [
                'announcements' => bannerAnnouncements(7),
            ])->render();

            expect(mb_substr_count($rendered, 'notifier-announcement notifier-announcement--'))->toBe(5);
            expect($rendered)
                ->toContain('+ 2 dalších oznámení')
                ->not->toContain('Oznámení 6.');
        });

        it('respects a custom cap value', function () {
            config(['notifier.announcements.max_visible' => 2]);

            $rendered = view('notifier::filament.announcements', [
                'announcements' => bannerAnnouncements(7),
            ])->render();

            expect(mb_substr_count($rendered, 'notifier-announcement notifier-announcement--'))->toBe(2);
            expect($rendered)
                ->toContain('Oznámení 1.')
                ->toContain('Oznámení 2.')
                ->not->toContain('Oznámení 3.')
                ->toContain('+ 5 dalších oznámení');
        });

        it('renders all announcements with no overflow line when the cap is 0 (unlimited)', function () {
            config(['notifier.announcements.max_visible' => 0]);

            $rendered = view('notifier::filament.announcements', [
                'announcements' => bannerAnnouncements(7),
            ])->render();

            expect(mb_substr_count($rendered, 'notifier-announcement notifier-announcement--'))->toBe(7);
            // The overflow ELEMENT must not render (the CSS class always lives in <style>).
            expect($rendered)
                ->toContain('Oznámení 7.')
                ->not->toContain('<div class="notifier-announcement__more">');
        });
    });
});

it('enables the announcements feature by default', function () {
    expect(config('notifier.features.announcements'))->toBeTrue()
        ->and(config('notifier.announcements.filament.enabled'))->toBeTrue()
        ->and(config('notifier.announcements.filament.render_hook'))->toBe('panels::content.start');
});
