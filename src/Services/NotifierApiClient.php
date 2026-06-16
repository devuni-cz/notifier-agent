<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
        $json = $response->json();

        if (is_array($json)) {
            if (isset($json['message']) && is_string($json['message'])) {
                $detail = $json['message'];

                if (isset($json['errors']) && is_array($json['errors'])) {
                    $detail .= ' '.json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
                }

                return $detail;
            }

            if (isset($json['errors']) && is_array($json['errors'])) {
                return json_encode($json['errors'], JSON_UNESCAPED_UNICODE);
            }
        }

        $body = $response->body();

        return $body !== '' ? $body : '(empty response)';
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
        ];
    }

    private function url(string $path): string
    {
        return $this->baseUrl().'/'.mb_ltrim($path, '/');
    }
}
