<?php

declare(strict_types=1);

use Devuni\Notifier\Services\NotifierApiClient;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'notifier.backup_url' => 'https://notifier.example.com/api/v1/repositories/42',
        'notifier.backup_code' => 'super-secret-token',
    ]);
});

function apiClient(): NotifierApiClient
{
    return app(NotifierApiClient::class);
}

describe('NotifierApiClient::baseUrl', function () {
    it('returns the configured HTTPS base URL without a trailing slash', function () {
        config(['notifier.backup_url' => 'https://notifier.example.com/api/v1/repositories/42/']);

        expect(apiClient()->baseUrl())->toBe('https://notifier.example.com/api/v1/repositories/42');
    });

    it('throws when the URL is not configured', function () {
        config(['notifier.backup_url' => null]);

        expect(fn () => apiClient()->baseUrl())->toThrow(RuntimeException::class, 'not configured');
    });

    it('throws when the URL is not HTTPS', function () {
        config(['notifier.backup_url' => 'http://notifier.example.com']);

        expect(fn () => apiClient()->baseUrl())->toThrow(RuntimeException::class, 'must use HTTPS');
    });
});

describe('NotifierApiClient requests', function () {
    it('GETs {baseUrl}/{path} with the token attached', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        apiClient()->get('/announcements');

        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && $request->url() === 'https://notifier.example.com/api/v1/repositories/42/announcements'
            && $request->hasHeader('X-Notifier-Token', 'super-secret-token'));
    });

    it('POSTs JSON to {baseUrl}/{path} with the token', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        apiClient()->post('/uploads/init', ['filename' => 'db.zip']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://notifier.example.com/api/v1/repositories/42/uploads/init'
            && $request['filename'] === 'db.zip'
            && $request->hasHeader('X-Notifier-Token', 'super-secret-token'));
    });

    it('GETs an absolute URL with the token (getAbsolute)', function () {
        Http::fake(['*' => Http::response(['status' => 'completed'], 200)]);

        apiClient()->getAbsolute('https://notifier.example.com/uploads/abc/status');

        Http::assertSent(fn ($request) => $request->url() === 'https://notifier.example.com/uploads/abc/status'
            && $request->hasHeader('X-Notifier-Token', 'super-secret-token'));
    });

    it('refuses to send the token when the base URL is not HTTPS', function () {
        config(['notifier.backup_url' => 'http://insecure.example.com']);
        Http::fake(['*' => Http::response([], 200)]);

        expect(fn () => apiClient()->get('/announcements'))->toThrow(RuntimeException::class, 'must use HTTPS');
        Http::assertNothingSent();
    });
});

describe('NotifierApiClient::formatError', function () {
    it('extracts the message and errors from a Laravel JSON error', function () {
        Http::fake(['*' => Http::response(['message' => 'Validation failed', 'errors' => ['field' => ['bad']]], 422)]);

        $response = apiClient()->get('/x');

        expect(apiClient()->formatError($response))
            ->toContain('Validation failed')
            ->toContain('bad');
    });

    it('falls back to the raw body when the response is not JSON', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        $response = apiClient()->get('/x');

        expect(apiClient()->formatError($response))->toBe('boom');
    });
});
