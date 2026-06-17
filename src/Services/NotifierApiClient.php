<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The single transport to the Notifier control plane.
 *
 * Every authenticated request to the server goes through here, so the security
 * invariants live in ONE place and cannot be forgotten by a new capability:
 *   - the base URL is HTTPS-only - a misconfigured `http://` URL is refused
 *     before the `X-Notifier-Token` secret can ride out over cleartext;
 *   - redirects are never followed on a token-bearing request (Guzzle re-sends
 *     custom headers across redirects, so a 30x could relay the secret elsewhere);
 *   - the token header and the configured base URL are applied uniformly.
 *
 * Success/failure handling stays with the caller: a push capability throws, a
 * pull capability swallows. This class only transports - it never decides policy.
 */
final class NotifierApiClient
{
    /**
     * Correlation id sent as X-Request-Id on every request of one backup run, so
     * the whole run (init -> chunks -> finalize -> poll) can be grepped on both
     * sides. Null between runs; an ad-hoc call (announcements/heartbeat) then gets
     * a fresh per-call id instead.
     */
    private ?string $requestId = null;

    /**
     * Pin a correlation id for the duration of a backup run. Validated against the
     * same charset the server accepts, so a bug can never make us send a header
     * the server would reject (and turn a backup into a confusing 4xx).
     */
    public function withRequestId(string $requestId): void
    {
        // The /D modifier is required: without it `$` also matches before a
        // trailing newline, letting "validid12\n" through as a "valid" id.
        if (preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $requestId) !== 1) {
            throw new RuntimeException('Invalid request id: '.$requestId);
        }

        $this->requestId = $requestId;
    }

    /**
     * Release the pinned run id (call in a finally) so it never bleeds into a
     * later, unrelated request on this singleton in a long-lived worker.
     */
    public function clearRequestId(): void
    {
        $this->requestId = null;
    }

    /**
     * The configured, HTTPS-enforced base URL (no trailing slash).
     *
     * @throws RuntimeException When NOTIFIER_URL is missing or not HTTPS.
     */
    public function baseUrl(): string
    {
        $baseUrl = config('notifier.backup_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('Notifier URL is not configured (set NOTIFIER_URL).');
        }

        if (! str_starts_with($baseUrl, 'https://')) {
            throw new RuntimeException('Notifier URL must use HTTPS: '.$baseUrl);
        }

        return mb_rtrim($baseUrl, '/');
    }

    /**
     * Authenticated GET to `{baseUrl}/{path}`.
     */
    public function get(string $path, int $timeout = 10): Response
    {
        return $this->pending($timeout)->get($this->url($path));
    }

    /**
     * Authenticated JSON POST to `{baseUrl}/{path}`.
     *
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = [], int $timeout = 30): Response
    {
        return $this->pending($timeout)->post($this->url($path), $data);
    }

    /**
     * Authenticated multipart POST to `{baseUrl}/{path}` with an attached stream.
     *
     * @param  resource  $resource
     * @param  array<string, string>  $headers
     */
    public function attachStream(string $path, string $field, $resource, string $filename, array $headers = [], int $timeout = 120): Response
    {
        return $this->pending($timeout)
            ->withHeaders($headers)
            ->attach($field, $resource, $filename)
            ->post($this->url($path));
    }

    /**
     * Authenticated GET to an ABSOLUTE url (e.g. a server-supplied status_url).
     *
     * The caller is responsible for validating the url's origin before handing
     * the token to it - the token is attached here. See ChunkedUploadService.
     */
    public function getAbsolute(string $url, int $timeout = 30): Response
    {
        return $this->pending($timeout)->get($url);
    }

    /**
     * Extract a meaningful error detail from a server response. Laravel APIs
     * typically return JSON with "message" and/or "errors"; falls back to body.
     */
    public function formatError(Response $response): string
    {
        return $this->extractMessage($response);
    }

    /**
     * Structured error detail for logging + correlation. On top of the human
     * message, surfaces the server's `error_id` and `request_id` (the server
     * returns them in the JSON body on a 5xx and echoes request_id on the
     * X-Request-Id header). Old servers omit them -> nulls, fully backward
     * compatible. Every id is sanitized so a tampered/odd response cannot inject
     * into our log lines.
     *
     * @return array{message: string, error_id: ?string, request_id: ?string, type: ?string}
     */
    public function errorDetails(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        return [
            'message' => $this->extractMessage($response),
            'error_id' => $this->safeId($json['error_id'] ?? null),
            'request_id' => $this->safeId($json['request_id'] ?? null)
                ?? $this->safeId($response->header('X-Request-Id')),
            'type' => $this->safeShort($json['type'] ?? null),
        ];
    }

    private function extractMessage(Response $response): string
    {
        $json = $response->json();

        if (is_array($json)) {
            if (isset($json['message']) && is_string($json['message'])) {
                $detail = $json['message'];

                if (isset($json['errors']) && is_array($json['errors'])) {
                    $detail .= ' '.json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
                }

                return $this->sanitizeMessage($detail);
            }

            if (isset($json['errors']) && is_array($json['errors'])) {
                return $this->sanitizeMessage((string) json_encode($json['errors'], JSON_UNESCAPED_UNICODE));
            }
        }

        $body = $response->body();

        return $body !== '' ? $this->sanitizeMessage($body) : '(empty response)';
    }

    /**
     * Strip control characters and cap the length of a server-supplied message
     * before it lands in an exception message or a log line - the same hygiene
     * already applied to failure_reason and the id fields, here closing the most
     * common error path. Plain messages are returned unchanged.
     */
    private function sanitizeMessage(string $value): string
    {
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value);

        return mb_substr($clean, 0, 500);
    }

    /**
     * Accept a server-supplied id only if it matches the safe charset/length,
     * otherwise drop it - never let a response field inject into a log line.
     */
    private function safeId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9._-]{8,64}$/D', $value) === 1
            ? $value
            : null;
    }

    private function safeShort(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $clean = (string) preg_replace('/[^A-Za-z0-9 _.\-]/', '', $value);

        return $clean === '' ? null : mb_substr($clean, 0, 64);
    }

    /**
     * A pending request pre-loaded with the transport invariants: timeout,
     * redirects disabled, JSON responses, and the X-Notifier-Token secret.
     */
    private function pending(int $timeout): PendingRequest
    {
        return Http::timeout($timeout)
            ->withOptions(['allow_redirects' => false])
            // Without an Accept header, Laravel's abort() on the server renders
            // an HTML error page, which would end up verbatim in our logs.
            ->acceptJson()
            ->withHeaders($this->authHeaders());
    }

    /**
     * Token + replay-signature headers applied to every request. The signature
     * is HMAC-SHA256 over "timestamp\nnonce" keyed by the SHA-256 hash of the
     * token (not the token itself) - the server stores that same hash at rest
     * (content_hash), so replay verification works even though the server never
     * holds the token plaintext. The server checks freshness + a single-use
     * nonce; servers that don't verify simply ignore the extra headers.
     *
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = (string) config('notifier.backup_code');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $hmacKey = hash('sha256', $token);

        return [
            'X-Notifier-Token' => $token,
            'X-Notifier-Timestamp' => $timestamp,
            'X-Notifier-Nonce' => $nonce,
            'X-Notifier-Signature' => hash_hmac('sha256', $timestamp."\n".$nonce, $hmacKey),
            'X-Request-Id' => $this->currentRequestId(),
        ];
    }

    /**
     * The pinned run id when inside a backup run, else a fresh per-call id so
     * every request still carries a greppable correlation id.
     */
    private function currentRequestId(): string
    {
        return $this->requestId ?? (string) Str::uuid();
    }

    private function url(string $path): string
    {
        return $this->baseUrl().'/'.mb_ltrim($path, '/');
    }
}
