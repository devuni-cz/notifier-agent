<?php

declare(strict_types=1);

use Devuni\Notifier\Services\ChunkedUploadService;
use Illuminate\Support\Facades\Http;

/**
 * Invoke the private status_url guard via reflection so we can test the
 * security boundary without driving the full upload + polling loop.
 */
function invokeStatusUrlGuard(string $statusUrl, string $baseUrl): void
{
    $service = app(ChunkedUploadService::class);
    $method = new ReflectionMethod($service, 'assertTrustedStatusUrl');
    $method->setAccessible(true);
    $method->invoke($service, $statusUrl, $baseUrl);
}

/**
 * Drive the private finalize-polling loop directly. Uses a 0s poll interval so
 * the terminal/failure/error branches can be asserted without real sleeps.
 */
function invokeWaitForCompletion(string $statusUrl, int $maxWaitSeconds = 10, int $pollIntervalSeconds = 0): void
{
    $service = app(ChunkedUploadService::class);
    $method = new ReflectionMethod($service, 'waitForCompletion');
    $method->setAccessible(true);
    $method->invoke($service, $statusUrl, 'upload-id', $maxWaitSeconds, $pollIntervalSeconds);
}

describe('ChunkedUploadService::assertTrustedStatusUrl', function () {
    it('accepts a same-origin HTTPS status_url', function () {
        invokeStatusUrlGuard(
            'https://notifier.example.com/uploads/abc-123/status',
            'https://notifier.example.com',
        );

        // Reaching this line means no exception was thrown.
        expect(true)->toBeTrue();
    });

    it('treats an explicit :443 port as equivalent to the implicit HTTPS port', function () {
        invokeStatusUrlGuard(
            'https://notifier.example.com:443/uploads/abc-123/status',
            'https://notifier.example.com',
        );

        expect(true)->toBeTrue();
    });

    it('rejects a cleartext http status_url', function () {
        expect(fn () => invokeStatusUrlGuard(
            'http://notifier.example.com/uploads/abc-123/status',
            'https://notifier.example.com',
        ))->toThrow(RuntimeException::class, 'Refusing to poll non-HTTPS status_url');
    });

    it('rejects a status_url on a different host', function () {
        expect(fn () => invokeStatusUrlGuard(
            'https://attacker.example.com/uploads/abc-123/status',
            'https://notifier.example.com',
        ))->toThrow(RuntimeException::class, 'Refusing to poll status_url on unexpected host');
    });

    it('rejects a look-alike subdomain suffix host', function () {
        expect(fn () => invokeStatusUrlGuard(
            'https://notifier.example.com.attacker.com/uploads/abc-123/status',
            'https://notifier.example.com',
        ))->toThrow(RuntimeException::class, 'Refusing to poll status_url on unexpected host');
    });

    it('rejects a status_url on a different port', function () {
        expect(fn () => invokeStatusUrlGuard(
            'https://notifier.example.com:8443/uploads/abc-123/status',
            'https://notifier.example.com',
        ))->toThrow(RuntimeException::class, 'Refusing to poll status_url on unexpected port');
    });
});

describe('ChunkedUploadService finalize polling', function () {
    it('never sends the token to a foreign status_url returned by finalize', function () {
        config([
            'notifier.backup_url' => 'https://notifier.example.com',
            'notifier.backup_code' => 'super-secret-token',
        ]);

        Http::fake([
            '*/uploads/init' => Http::response(['upload_id' => 'abc-123'], 200),
            '*/uploads/*/chunks/*' => Http::response([], 200),
            '*/uploads/*/finalize' => Http::response(
                ['status_url' => 'https://attacker.example.com/poll'],
                202,
            ),
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'notifier_test_');
        file_put_contents($tmpPath, 'backup payload');

        try {
            // The guard must fire at finalize time - before any poll/sleep happens.
            expect(fn () => app(ChunkedUploadService::class)
                ->upload($tmpPath, 'database'))
                ->toThrow(RuntimeException::class, 'Refusing to poll status_url on unexpected host');

            // The long-lived secret must never have left for the attacker host.
            Http::assertNotSent(
                fn ($request) => str_contains($request->url(), 'attacker.example.com'),
            );
        } finally {
            @unlink($tmpPath);
        }
    });

    it('attaches the token only to the configured backup origin on every upload request', function () {
        config([
            'notifier.backup_url' => 'https://notifier.example.com',
            'notifier.backup_code' => 'super-secret-token',
        ]);

        Http::fake([
            '*/uploads/init' => Http::response(['upload_id' => 'abc-123'], 200),
            '*/uploads/*/chunks/*' => Http::response([], 200),
            // 200 (sync finalize) keeps the test off the polling path.
            '*/uploads/*/finalize' => Http::response(['status' => 'completed'], 200),
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'notifier_test_');
        file_put_contents($tmpPath, 'backup payload');

        try {
            app(ChunkedUploadService::class)->upload($tmpPath, 'database');

            // init + one chunk + finalize, each carrying the secret to the trusted origin.
            Http::assertSentCount(3);
            Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://notifier.example.com')
                && $request->hasHeader('X-Notifier-Token', 'super-secret-token'));
            // The secret must never leave for any other host.
            Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'notifier.example.com'));
        } finally {
            @unlink($tmpPath);
        }
    });
});

describe('ChunkedUploadService::waitForCompletion', function () {
    beforeEach(function () {
        config(['notifier.backup_code' => 'super-secret-token']);
    });

    it('returns once the server reports a completed terminal status', function () {
        Http::fake([
            '*/status' => Http::response(['status' => 'completed', 'is_terminal' => true], 200),
        ]);

        invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status');

        expect(true)->toBeTrue(); // no exception thrown
    });

    it('throws with the server-supplied reason on a failed terminal status', function () {
        Http::fake([
            '*/status' => Http::response(['status' => 'failed', 'is_terminal' => true, 'failure_reason' => 'checksum mismatch'], 200),
        ]);

        expect(fn () => invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status'))
            ->toThrow(RuntimeException::class, 'checksum mismatch');
    });

    it('keeps polling through non-terminal statuses until completion', function () {
        Http::fake([
            '*/status' => Http::sequence()
                ->push(['status' => 'processing', 'is_terminal' => false], 200)
                ->push(['status' => 'processing', 'is_terminal' => false], 200)
                ->push(['status' => 'completed', 'is_terminal' => true], 200),
        ]);

        invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status');

        expect(true)->toBeTrue();
    });

    it('sanitizes control characters out of the server failure reason', function () {
        Http::fake([
            '*/status' => Http::response(['status' => 'failed', 'is_terminal' => true, 'failure_reason' => "line1\nline2\tend"], 200),
        ]);

        try {
            invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status');
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('line1 line2 end');
            expect($e->getMessage())->not->toContain("\n");
            expect($e->getMessage())->not->toContain("\t");
        }
    });

    it('sanitizes the server-supplied status so a crafted value cannot forge completion', function () {
        // A compromised server returning "completed\n<forged log line>" must not
        // be accepted as a successful backup: the status is control-char-stripped
        // before the === 'completed' check (and before it reaches the log line),
        // so the laced value no longer equals 'completed' and falls through.
        Http::fake([
            '*/status' => Http::response(['status' => "completed\nINJECTED ENTRY", 'is_terminal' => true], 200),
        ]);

        expect(fn () => invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status'))
            ->toThrow(RuntimeException::class);
    });

    it('gives up after repeated polling errors', function () {
        Http::fake([
            '*/status' => Http::response('upstream exploded', 500),
        ]);

        expect(fn () => invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status', 100))
            ->toThrow(RuntimeException::class, 'Status polling kept returning HTTP 500');
    });

    it('throws a timeout error when the deadline elapses before a terminal status', function () {
        Http::fake([
            '*/status' => Http::response(['status' => 'processing', 'is_terminal' => false], 200),
        ]);

        expect(fn () => invokeWaitForCompletion('https://notifier.example.com/uploads/abc/status', 0))
            ->toThrow(RuntimeException::class, 'did not finalize within 0s');
    });
});

describe('ChunkedUploadService request id correlation', function () {
    beforeEach(function () {
        config([
            'notifier.backup_url' => 'https://notifier.example.com',
            'notifier.backup_code' => 'super-secret-token',
        ]);
    });

    it('sends the same X-Request-Id across init, chunk and finalize of one run', function () {
        Http::fake([
            '*/uploads/init' => Http::response(['upload_id' => 'abc-123'], 200),
            '*/uploads/*/chunks/*' => Http::response([], 200),
            '*/uploads/*/finalize' => Http::response(['status' => 'completed'], 200),
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'notifier_test_');
        file_put_contents($tmpPath, 'backup payload');

        try {
            app(ChunkedUploadService::class)->upload($tmpPath, 'database');

            $ids = [];
            Http::assertSent(function ($request) use (&$ids) {
                $ids[] = $request->header('X-Request-Id')[0] ?? '';

                return true;
            });

            expect($ids)->toHaveCount(3)
                ->and(array_unique($ids))->toHaveCount(1) // one shared id for the whole run
                ->and(preg_match('/^[A-Za-z0-9._-]{8,64}$/', $ids[0]))->toBe(1);
        } finally {
            @unlink($tmpPath);
        }
    });

    it('surfaces the server error_id and request_id in the thrown exception on a 5xx', function () {
        Http::fake([
            '*/uploads/init' => Http::response([
                'type' => 'error',
                'message' => 'Internal Server Error',
                'error_id' => 'err-deadbeef',
                'request_id' => 'req-cafebabe',
            ], 500),
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'notifier_test_');
        file_put_contents($tmpPath, 'backup payload');

        try {
            app(ChunkedUploadService::class)->upload($tmpPath, 'database');
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('Failed to initialize upload: HTTP 500')
                ->toContain('error_id=err-deadbeef')
                ->toContain('request_id=req-cafebabe')
                // The token must never leak into the surfaced message.
                ->not->toContain('super-secret-token');
        } finally {
            @unlink($tmpPath);
        }
    });

    it('appends no id suffix against an old server that returns no ids', function () {
        Http::fake([
            '*/uploads/init' => Http::response(['message' => 'Server Error'], 500),
        ]);

        $tmpPath = tempnam(sys_get_temp_dir(), 'notifier_test_');
        file_put_contents($tmpPath, 'backup payload');

        try {
            app(ChunkedUploadService::class)->upload($tmpPath, 'database');
            $this->fail('Expected a RuntimeException to be thrown.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toBe('Failed to initialize upload: HTTP 500 - Server Error')
                ->not->toContain('error_id=')
                ->not->toContain('request_id=');
        } finally {
            @unlink($tmpPath);
        }
    });
});
