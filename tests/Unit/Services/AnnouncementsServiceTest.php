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
                    ['content' => 'Maintenance on 2026-06-30', 'severity' => 'warning'],
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

describe('AnnouncementsService::repositoryId', function () {
    it('parses the repository id from the configured NOTIFIER_URL', function () {
        expect(announcementsService()->repositoryId())->toBe('52740614');
    });

    it('returns null when the URL has no repositories segment', function () {
        config(['notifier.backup_url' => 'https://notifier.devuni.cz/api/v1/uploads']);

        expect(announcementsService()->repositoryId())->toBeNull();
    });
});
