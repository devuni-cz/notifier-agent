<?php

declare(strict_types=1);

use Devuni\Notifier\Services\HeartbeatService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'notifier.features.heartbeat' => true,
        'notifier.features.announcements' => true,
        'notifier.backup_url' => 'https://notifier.devuni.cz/api/v1/repositories/52740614',
        'notifier.backup_code' => 'super-secret-token',
        'notifier.queue_connection' => 'redis',
    ]);

    Cache::flush();
});

function heartbeatService(): HeartbeatService
{
    return app(HeartbeatService::class);
}

describe('HeartbeatService::gatherManifest', function () {
    it('builds a manifest with every wire-contract key', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest)->toHaveKeys([
            'agent_version',
            'php_version',
            'laravel_version',
            'queue_connection',
            'enabled_features',
            'disk_free_bytes',
            'disk_total_bytes',
            'last_database_backup_at',
            'last_storage_backup_at',
            'reported_at',
        ]);
    });

    it('reports the running PHP and Laravel versions', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['php_version'])->toBe(PHP_VERSION)
            ->and($manifest['laravel_version'])->toBe(app()->version());
    });

    it('reports the agent version as a non-empty string', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['agent_version'])->toBeString()
            ->and($manifest['agent_version'])->not->toBe('');
    });

    it('stamps the agent clock in reported_at', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['reported_at'])->toBeString()
            ->and($manifest['reported_at'])->not->toBe('');
    });

    it('reports the configured queue connection', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['queue_connection'])->toBe('redis');
    });

    it('falls back to the default queue connection when notifier.queue_connection is unset', function () {
        // A host that removed the key from their published config: config()'s
        // default fires only when the key is absent, so rebuild the notifier
        // config without it (an explicit null would be returned as-is, never the
        // fallback).
        $notifier = config('notifier');
        unset($notifier['queue_connection']);
        config(['notifier' => $notifier, 'queue.default' => 'database']);

        expect(heartbeatService()->gatherManifest()['queue_connection'])->toBe('database');
    });

    it('exposes the enabled feature map as booleans', function () {
        config([
            'notifier.features.announcements' => false,
            'notifier.features.heartbeat' => true,
        ]);

        $features = heartbeatService()->gatherManifest()['enabled_features'];

        expect($features)->toBeArray()
            ->and($features['announcements'])->toBeFalse()
            ->and($features['heartbeat'])->toBeTrue()
            ->and($features['backups'])->toBeBool();
    });

    it('reports disk bytes as int or null', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['disk_free_bytes'] === null || is_int($manifest['disk_free_bytes']))->toBeTrue();
        expect($manifest['disk_total_bytes'] === null || is_int($manifest['disk_total_bytes']))->toBeTrue();
    });

    it('reports null last-backup timestamps when no backup has ever run', function () {
        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['last_database_backup_at'])->toBeNull()
            ->and($manifest['last_storage_backup_at'])->toBeNull();
    });

    it('reads the last-backup timestamps from the cache when present', function () {
        Cache::forever(HeartbeatService::LAST_DATABASE_BACKUP_KEY, '2026-06-14T02:00:00+00:00');
        Cache::forever(HeartbeatService::LAST_STORAGE_BACKUP_KEY, '2026-06-14T03:00:00+00:00');

        $manifest = heartbeatService()->gatherManifest();

        expect($manifest['last_database_backup_at'])->toBe('2026-06-14T02:00:00+00:00')
            ->and($manifest['last_storage_backup_at'])->toBe('2026-06-14T03:00:00+00:00');
    });
});

describe('HeartbeatService::sendHeartbeat', function () {
    it('POSTs the manifest to {backup_url}/heartbeat with the X-Notifier-Token header', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $manifest = heartbeatService()->gatherManifest();
        heartbeatService()->sendHeartbeat($manifest);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://notifier.devuni.cz/api/v1/repositories/52740614/heartbeat'
            && $request->hasHeader('X-Notifier-Token', 'super-secret-token')
            && $request['agent_version'] === $manifest['agent_version']
            && $request['php_version'] === PHP_VERSION);
    });

    it('throws on a 4xx response', function () {
        Http::fake(['*' => Http::response(['message' => 'Forbidden'], 403)]);

        expect(fn () => heartbeatService()->sendHeartbeat(heartbeatService()->gatherManifest()))
            ->toThrow(RuntimeException::class, 'Notifier heartbeat rejected');
    });

    it('throws on a 5xx response', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        expect(fn () => heartbeatService()->sendHeartbeat(heartbeatService()->gatherManifest()))
            ->toThrow(RuntimeException::class, 'Notifier heartbeat rejected');
    });

    it('throws on a transport (connection) error', function () {
        Http::fake(['*' => fn () => throw new ConnectionException('refused')]);

        expect(fn () => heartbeatService()->sendHeartbeat(heartbeatService()->gatherManifest()))
            ->toThrow(RuntimeException::class, 'Failed to send notifier heartbeat');
    });

    it('does nothing (no POST) when the heartbeat feature is disabled', function () {
        config(['notifier.features.heartbeat' => false]);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        heartbeatService()->sendHeartbeat(heartbeatService()->gatherManifest());

        Http::assertNothingSent();
    });
});
