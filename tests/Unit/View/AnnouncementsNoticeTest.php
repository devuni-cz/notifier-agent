<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'notifier.features.announcements' => true,
        'notifier.backup_url' => 'https://notifier.devuni.cz/api/v1/repositories/52740614',
        'notifier.backup_code' => 'super-secret-token',
    ]);
});

describe('<x-notifier-announcements-notice />', function () {
    it('renders the announcement content when an announcement is active', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Maintenance on 2026-06-30, ~5h downtime.', 'severity' => 'high'],
                ],
            ], 200),
        ]);

        $this->blade('<x-notifier-announcements-notice />')
            ->assertSee('Maintenance on 2026-06-30, ~5h downtime.')
            ->assertSee('notifier-announcement--high', false);
    });

    it('renders the type chip and id attribute when the server sends them', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['id' => 7, 'content' => 'Odstávka v neděli.', 'severity' => 'high', 'type' => 'outage'],
                ],
            ], 200),
        ]);

        $this->blade('<x-notifier-announcements-notice />')
            ->assertSee('Výpadek')
            ->assertSee('notifier-announcement--type-outage', false)
            ->assertSee('data-announcement-id="7"', false);
    });

    it('renders the validity-window sub-line when the server sends start/end dates', function () {
        config(['app.timezone' => 'Europe/Prague']);

        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    [
                        'content' => 'Plánovaná údržba.',
                        'severity' => 'high',
                        'starts_at' => '2026-06-13T21:12:00+00:00',
                        'ends_at' => '2026-06-14T04:00:00+00:00',
                    ],
                ],
            ], 200),
        ]);

        $this->blade('<x-notifier-announcements-notice />')
            ->assertSee('notifier-announcement__validity', false)
            ->assertSee('Platí: 13. 6. 2026 23:12 - 14. 6. 2026 06:00');
    });

    it('omits the validity sub-line when the server sends no start date', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'No window.', 'severity' => 'info'],
                ],
            ], 200),
        ]);

        $this->blade('<x-notifier-announcements-notice />')
            ->assertDontSee('notifier-announcement__validity', false);
    });

    it('renders nothing when there are no active announcements', function () {
        Http::fake(['*/announcements' => Http::response(['announcements' => []], 200)]);

        $rendered = mb_trim($this->blade('<x-notifier-announcements-notice />')->__toString());

        expect($rendered)->toBe('');
    });

    it('renders nothing when the feature is disabled', function () {
        config(['notifier.features.announcements' => false]);
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x']]], 200)]);

        $rendered = mb_trim($this->blade('<x-notifier-announcements-notice />')->__toString());

        expect($rendered)->toBe('');
        Http::assertNothingSent();
    });

    describe('render cap', function () {
        /**
         * Fake the wire with $count priority-ordered announcements. The server
         * already sorts by priority, so the view must keep the first N as-is.
         */
        function fakeNoticeAnnouncements(int $count): void
        {
            Http::fake([
                '*/announcements' => Http::response([
                    'announcements' => array_map(
                        static fn (int $i): array => ['content' => "Oznámení {$i}.", 'severity' => 'info'],
                        range(1, $count),
                    ),
                ], 200),
            ]);
        }

        it('renders only the top-N and a "+ N dalších" line when over the cap', function () {
            config(['notifier.announcements.max_visible' => 5]);
            fakeNoticeAnnouncements(7);

            $this->blade('<x-notifier-announcements-notice />')
                ->assertSee('Oznámení 1.')
                ->assertSee('Oznámení 5.')
                ->assertDontSee('Oznámení 6.')
                ->assertDontSee('Oznámení 7.')
                ->assertSee('notifier-announcement__more', false)
                ->assertSee('+ 2 dalších oznámení');
        });

        it('renders everything and no overflow line when at or under the cap', function () {
            config(['notifier.announcements.max_visible' => 5]);
            fakeNoticeAnnouncements(3);

            $this->blade('<x-notifier-announcements-notice />')
                ->assertSee('Oznámení 3.')
                ->assertDontSee('notifier-announcement__more', false);
        });

        it('defaults the cap to 5 when the config key is absent', function () {
            // Drop the key entirely (rewrite the array without it) so the view's own
            // `config(..., 5)` default kicks in. Setting it to null would not - the
            // key would still exist and config() would return that null.
            $announcements = config('notifier.announcements');
            unset($announcements['max_visible']);
            config(['notifier.announcements' => $announcements]);
            fakeNoticeAnnouncements(7);

            $this->blade('<x-notifier-announcements-notice />')
                ->assertSee('Oznámení 5.')
                ->assertDontSee('Oznámení 6.')
                ->assertSee('+ 2 dalších oznámení');
        });

        it('respects a custom cap value', function () {
            config(['notifier.announcements.max_visible' => 2]);
            fakeNoticeAnnouncements(7);

            $this->blade('<x-notifier-announcements-notice />')
                ->assertSee('Oznámení 2.')
                ->assertDontSee('Oznámení 3.')
                ->assertSee('+ 5 dalších oznámení');
        });

        it('renders all announcements with no overflow line when the cap is 0 (unlimited)', function () {
            config(['notifier.announcements.max_visible' => 0]);
            fakeNoticeAnnouncements(7);

            $this->blade('<x-notifier-announcements-notice />')
                ->assertSee('Oznámení 7.')
                ->assertDontSee('notifier-announcement__more', false);
        });
    });
});
