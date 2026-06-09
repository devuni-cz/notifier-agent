<?php

declare(strict_types=1);

use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Illuminate\Support\Facades\Http;

/**
 * Invoke the private status_url guard via reflection so we can test the
 * security boundary without driving the full upload + polling loop.
 */
function invokeStatusUrlGuard(string $statusUrl, string $baseUrl): void
{
    $service = new ChunkedUploadService(new NotifierLoggerService());
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
    $service = new ChunkedUploadService(new NotifierLoggerService());
    $method = new ReflectionMethod($service, 'waitForCompletion');
    $method->setAccessible(true);
    $method->invoke($service, $statusUrl, 'super-secret-token', 'upload-id', $maxWaitSeconds, $pollIntervalSeconds);
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
            expect(fn () => (new ChunkedUploadService(new NotifierLoggerService()))
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
            (new ChunkedUploadService(new NotifierLoggerService()))->upload($tmpPath, 'database');

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
