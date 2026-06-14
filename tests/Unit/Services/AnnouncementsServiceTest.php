<?php

declare(strict_types=1);

use Devuni\Notifier\Services\AnnouncementsService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'notifier.features.announcements' => true,
        'notifier.backup_url' => 'https://notifier.devuni.cz/api/v1/repositories/52740614',
        'notifier.backup_code' => 'super-secret-token',
        'notifier.announcements.cache_ttl' => 900,
        'notifier.announcements.failure_cache_ttl' => 60,
    ]);
});

function announcementsService(): AnnouncementsService
{
    return app(AnnouncementsService::class);
}

describe('AnnouncementsService::activeAnnouncements', function () {
    it('returns this site\'s announcements from the server on success', function () {
        Http::fake([
            '*/repositories/52740614/announcements' => Http::response([
                'announcements' => [
                    // 'high' is a real AnnouncementSeverityEnum value the server can send
                    // (critical/high/medium/low/info) — keep the contract pinned to reality.
                    ['content' => 'Maintenance on 2026-06-30', 'severity' => 'high'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements)->toHaveCount(1);
        expect($announcements[0]['content'])->toBe('Maintenance on 2026-06-30');
    });

    it('requests {backup_url}/announcements with the X-Notifier-Token header', function () {
        Http::fake(['*' => Http::response(['announcements' => []], 200)]);

        announcementsService()->activeAnnouncements();

        Http::assertSent(fn ($request) => $request->url() === 'https://notifier.devuni.cz/api/v1/repositories/52740614/announcements'
            && $request->hasHeader('X-Notifier-Token', 'super-secret-token'));
    });

    it('returns an empty array when the announcements feature is disabled (and makes no request)', function () {
        config(['notifier.features.announcements' => false]);
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x']]], 200)]);

        expect(announcementsService()->activeAnnouncements())->toBe([]);
        Http::assertNothingSent();
    });

    it('returns an empty array when no backup URL is configured', function () {
        config(['notifier.backup_url' => null]);
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x']]], 200)]);

        expect(announcementsService()->activeAnnouncements())->toBe([]);
        Http::assertNothingSent();
    });

    it('refuses to send the token over a non-HTTPS URL (never leaks the secret in cleartext)', function () {
        config(['notifier.backup_url' => 'http://insecure.example.com/api/v1/repositories/1']);
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x']]], 200)]);

        expect(announcementsService()->activeAnnouncements())->toBe([]);
        Http::assertNothingSent();
    });

    it('returns an empty array (and does not throw) on a server error', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        expect(announcementsService()->activeAnnouncements())->toBe([]);
    });

    it('returns an empty array (and does not throw) on a connection failure', function () {
        Http::fake(['*' => fn () => throw new Illuminate\Http\Client\ConnectionException('refused')]);

        expect(announcementsService()->activeAnnouncements())->toBe([]);
    });

    it('caches a successful response so a second call does not hit the server', function () {
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x']]], 200)]);

        announcementsService()->activeAnnouncements();
        announcementsService()->activeAnnouncements();

        Http::assertSentCount(1);
    });

    it('negative-caches a failure so it does not retry on every call', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        announcementsService()->activeAnnouncements();
        announcementsService()->activeAnnouncements();

        Http::assertSentCount(1);
    });
});

describe('AnnouncementsService placement normalization', function () {
    it('defaults dashboard_type to filament and target to null on older payloads', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Old server payload.', 'severity' => 'info'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0]['dashboard_type'])->toBe('filament')
            ->and($announcements[0]['target'])->toBeNull();
    });

    it('passes the wire dashboard_type and target through intact', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Targeted.', 'severity' => 'info', 'dashboard_type' => 'custom', 'target' => 'spa-banner'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0]['dashboard_type'])->toBe('custom')
            ->and($announcements[0]['target'])->toBe('spa-banner');
    });
});

describe('AnnouncementsService::customAnnouncements', function () {
    it('returns only announcements whose dashboard_type is custom', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Filament one.', 'severity' => 'info'],
                    ['content' => 'Custom one.', 'severity' => 'info', 'dashboard_type' => 'custom', 'target' => 'spa-banner'],
                    ['content' => 'Filament two.', 'severity' => 'info', 'dashboard_type' => 'filament'],
                ],
            ], 200),
        ]);

        $custom = announcementsService()->customAnnouncements();

        expect($custom)->toHaveCount(1)
            ->and($custom[0]['content'])->toBe('Custom one.');
    });

    it('narrows to a single target element id when one is given', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Banner.', 'severity' => 'info', 'dashboard_type' => 'custom', 'target' => 'spa-banner'],
                    ['content' => 'Sidebar.', 'severity' => 'info', 'dashboard_type' => 'custom', 'target' => 'spa-sidebar'],
                ],
            ], 200),
        ]);

        $custom = announcementsService()->customAnnouncements('spa-sidebar');

        expect($custom)->toHaveCount(1)
            ->and($custom[0]['content'])->toBe('Sidebar.');
    });

    it('returns an empty list (and does not throw) when the feature is disabled', function () {
        config(['notifier.features.announcements' => false]);
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x', 'dashboard_type' => 'custom']]], 200)]);

        expect(announcementsService()->customAnnouncements())->toBe([]);
        Http::assertNothingSent();
    });
});

describe('AnnouncementsService validity window', function () {
    it('builds "Platí: start – end" in the host timezone with an absolute format when both dates are present', function () {
        // 21:12 UTC in June is 23:12 in Europe/Prague (UTC+2, DST). The wire is
        // UTC; the client must see its own local time, formatted absolutely so it
        // can be cached for 15 min without going stale.
        config(['app.timezone' => 'Europe/Prague']);

        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    [
                        'content' => 'Window.',
                        'severity' => 'info',
                        'starts_at' => '2026-06-13T21:12:00+00:00',
                        'ends_at' => '2026-06-14T04:00:00+00:00',
                    ],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0]['validity_label'])->toBe('Platí: 13. 6. 2026 23:12 – 14. 6. 2026 06:00');
    });

    it('builds "Platí od start (do odvolání)" when ends_at is null', function () {
        config(['app.timezone' => 'Europe/Prague']);

        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    [
                        'content' => 'Open-ended.',
                        'severity' => 'info',
                        'starts_at' => '2026-06-13T21:12:00+00:00',
                        'ends_at' => null,
                    ],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0]['validity_label'])->toBe('Platí od 13. 6. 2026 23:12 (do odvolání)');
    });

    it('treats a missing/empty ends_at the same as null (do odvolání)', function () {
        config(['app.timezone' => 'Europe/Prague']);

        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'No end key.', 'severity' => 'info', 'starts_at' => '2026-06-13T21:12:00+00:00'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0]['validity_label'])->toBe('Platí od 13. 6. 2026 23:12 (do odvolání)');
    });

    it('returns null when starts_at is missing', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'No start.', 'severity' => 'info', 'ends_at' => '2026-06-14T04:00:00+00:00'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0])->toHaveKey('validity_label')
            ->and($announcements[0]['validity_label'])->toBeNull();
    });

    it('never throws on a garbage starts_at and returns null (fail-soft)', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Bad date.', 'severity' => 'info', 'starts_at' => 'not-a-date', 'ends_at' => 'also-bad'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0]['validity_label'])->toBeNull();
    });

    it('exposes validity_label under the public key constant', function () {
        config(['app.timezone' => 'Europe/Prague']);

        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Keyed.', 'severity' => 'info', 'starts_at' => '2026-06-13T21:12:00+00:00'],
                ],
            ], 200),
        ]);

        $announcements = announcementsService()->activeAnnouncements();

        expect($announcements[0][AnnouncementsService::VALIDITY_LABEL_KEY])->toBe('Platí od 13. 6. 2026 23:12 (do odvolání)');
    });

    it('caches the validity_label as an absolute string (not a relative phrase that would go stale)', function () {
        config(['app.timezone' => 'Europe/Prague']);

        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    [
                        'content' => 'Cached.',
                        'severity' => 'info',
                        'starts_at' => '2026-06-13T21:12:00+00:00',
                        'ends_at' => '2026-06-14T04:00:00+00:00',
                    ],
                ],
            ], 200),
        ]);

        // First call populates the cache; the second is served from cache (no
        // second HTTP request) and must return the identical absolute string.
        $first = announcementsService()->activeAnnouncements();
        $second = announcementsService()->activeAnnouncements();

        Http::assertSentCount(1);

        expect($first[0]['validity_label'])
            ->toBe('Platí: 13. 6. 2026 23:12 – 14. 6. 2026 06:00')
            ->and($second[0]['validity_label'])->toBe($first[0]['validity_label'])
            // Absolute, not a diffForHumans phrase.
            ->and($second[0]['validity_label'])->not->toContain('před')
            ->and($second[0]['validity_label'])->not->toContain('ago');
    });
});

describe('AnnouncementsService::repositoryId', function () {
    it('parses the repository id from the configured NOTIFIER_URL', function () {
        expect(announcementsService()->repositoryId())->toBe('52740614');
    });

    it('returns null when the URL has no repositories segment', function () {
        config(['notifier.backup_url' => 'https://notifier.devuni.cz/api/v1/uploads']);

        expect(announcementsService()->repositoryId())->toBeNull();
    });
});
