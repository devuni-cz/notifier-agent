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

    it('requests JSON responses (Accept: application/json)', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        apiClient()->get('/announcements');

        Http::assertSent(fn ($request) => $request->hasHeader('Accept', 'application/json'));
    });

    it('never follows a redirect on a token-bearing request', function () {
        // Guzzle re-sends custom headers across redirects, so a 30x from the
        // server would relay X-Notifier-Token to the Location target. The
        // transport must surface the 30x instead of following it.
        Http::fake([
            'https://notifier.example.com/*' => Http::response('', 301, [
                'Location' => 'https://evil.example.com/steal',
            ]),
            'https://evil.example.com/*' => Http::response('gotcha', 200),
        ]);

        $response = apiClient()->get('/announcements');

        expect($response->status())->toBe(301);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
        Http::assertSentCount(1);
    });
});

describe('NotifierApiClient replay signature', function () {
    it('attaches a verifiable timestamp/nonce/signature to every request', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        apiClient()->get('/announcements');

        Http::assertSent(function ($request) {
            $token = 'super-secret-token';
            $ts = $request->header('X-Notifier-Timestamp')[0] ?? '';
            $nonce = $request->header('X-Notifier-Nonce')[0] ?? '';
            $sig = $request->header('X-Notifier-Signature')[0] ?? '';

            return $ts !== '' && ctype_digit($ts)
                && $nonce !== ''
                && hash_equals(hash_hmac('sha256', $ts."\n".$nonce, hash('sha256', $token)), $sig);
        });
    });

    it('uses a fresh nonce for each request', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        apiClient()->get('/a');
        apiClient()->get('/b');

        $nonces = [];
        Http::assertSent(function ($request) use (&$nonces) {
            $nonces[] = $request->header('X-Notifier-Nonce')[0] ?? '';

            return true;
        });

        expect($nonces)->toHaveCount(2)
            ->and($nonces[0])->not->toBe($nonces[1]);
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
