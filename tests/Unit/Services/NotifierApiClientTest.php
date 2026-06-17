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

    it('strips control characters out of a server-supplied message', function () {
        Http::fake(['*' => Http::response(['message' => "line1\nline2\ttabbed"], 500)]);

        $message = apiClient()->formatError(apiClient()->get('/x'));

        expect($message)->toContain('line1 line2 tabbed')
            ->not->toContain("\n")
            ->and($message)->not->toContain("\t");
    });
});

describe('NotifierApiClient request id correlation', function () {
    it('sends a valid X-Request-Id on every request even without a pinned id', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        apiClient()->get('/announcements');

        Http::assertSent(function ($request) {
            $id = $request->header('X-Request-Id')[0] ?? '';

            return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $id) === 1;
        });
    });

    it('reuses the pinned run id across every request of the run', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $client = apiClient();
        $client->withRequestId('run-12345678');
        $client->get('/a');
        $client->post('/b');

        $ids = [];
        Http::assertSent(function ($request) use (&$ids) {
            $ids[] = $request->header('X-Request-Id')[0] ?? '';

            return true;
        });

        expect($ids)->toHaveCount(2)
            ->and($ids[0])->toBe('run-12345678')
            ->and($ids[1])->toBe('run-12345678');
    });

    it('rejects a pinned run id with a trailing newline (regex /D anchor)', function () {
        // Without the /D modifier PCRE `$` matches before a final newline, so
        // "run-12345678\n" would be accepted and inject a newline downstream.
        expect(fn () => apiClient()->withRequestId("run-12345678\n"))
            ->toThrow(RuntimeException::class, 'Invalid request id');
    });

    it('uses a fresh per-call id again after the run id is cleared', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $client = apiClient();
        $client->withRequestId('run-12345678');
        $client->get('/a');
        $client->clearRequestId();
        $client->get('/b');

        $ids = [];
        Http::assertSent(function ($request) use (&$ids) {
            $ids[] = $request->header('X-Request-Id')[0] ?? '';

            return true;
        });

        expect($ids[0])->toBe('run-12345678')
            ->and($ids[1])->not->toBe('run-12345678');
    });

    it('rejects a malformed request id', function () {
        expect(fn () => apiClient()->withRequestId("bad id\nwith spaces"))
            ->toThrow(RuntimeException::class, 'Invalid request id');
    });
});

describe('NotifierApiClient::errorDetails', function () {
    it('surfaces the server error_id and request_id from the JSON body', function () {
        Http::fake(['*' => Http::response([
            'type' => 'error',
            'message' => 'Internal Server Error',
            'error_id' => 'err-abcdef12',
            'request_id' => 'req-abcdef12',
        ], 500)]);

        $details = apiClient()->errorDetails(apiClient()->get('/x'));

        expect($details['message'])->toBe('Internal Server Error')
            ->and($details['error_id'])->toBe('err-abcdef12')
            ->and($details['request_id'])->toBe('req-abcdef12')
            ->and($details['type'])->toBe('error');
    });

    it('falls back to the X-Request-Id header when the body omits request_id', function () {
        Http::fake(['*' => Http::response(['message' => 'boom'], 500, ['X-Request-Id' => 'hdr-abcdef12'])]);

        $details = apiClient()->errorDetails(apiClient()->get('/x'));

        expect($details['request_id'])->toBe('hdr-abcdef12')
            ->and($details['error_id'])->toBeNull();
    });

    it('drops malformed ids so they cannot inject into log lines', function () {
        Http::fake(['*' => Http::response(['message' => 'boom', 'error_id' => "x\ninjection", 'request_id' => 'short'], 500)]);

        $details = apiClient()->errorDetails(apiClient()->get('/x'));

        expect($details['error_id'])->toBeNull()
            ->and($details['request_id'])->toBeNull();
    });
});
