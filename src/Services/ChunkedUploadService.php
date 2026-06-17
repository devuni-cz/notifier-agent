<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ChunkedUploadService
{
    /** Correlation id for the current backup run; null between runs. */
    private ?string $requestId = null;

    public function __construct(
        private readonly NotifierApiClient $api,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    /**
     * Upload a file using the chunked upload protocol.
     *
     * 1. Init upload → get upload_id
     * 2. Send chunks sequentially with per-chunk retry
     * 3. Finalize upload → server reassembles and verifies
     */
    public function upload(string $path, string $backupType): void
    {
        $logger = $this->notifierLogger->get();

        // Validate the target up front: the shared client enforces HTTPS-only and
        // throws before any chunking work begins if NOTIFIER_URL is missing/insecure.
        $this->api->baseUrl();

        // One correlation id for the whole run (init -> chunks -> finalize -> poll),
        // pinned on the client so every request carries the same X-Request-Id, and
        // cleared in finally so it never bleeds into a later call on the singleton.
        $this->requestId = (string) Str::uuid();
        $this->api->withRequestId($this->requestId);

        try {
            $chunkSize = (int) config('notifier.chunk_size', 20 * 1024 * 1024);
            $fileSize = filesize($path);

            if ($fileSize === false) {
                throw new RuntimeException('Failed to read file size: '.$path);
            }

            $checksum = hash_file('sha256', $path);

            if ($checksum === false) {
                throw new RuntimeException('Failed to compute file checksum: '.$path);
            }

            $totalChunks = (int) ceil($fileSize / $chunkSize);
            $filename = basename($path);

            $logger->info('📦 starting chunked upload', $this->logContext('init_upload', [
                'file' => $filename,
                'size' => $fileSize,
                'chunks' => $totalChunks,
                'chunk_size' => $chunkSize,
            ]));

            // Phase 1: Init upload
            $uploadId = $this->initUpload($backupType, $filename, $fileSize, $totalChunks, $checksum);

            $logger->info('✅ upload initialized', $this->logContext('init_upload', ['upload_id' => $uploadId]));

            // Phase 2: Send chunks (streamed to temp files to avoid memory exhaustion)
            $handle = fopen($path, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Could not open file for reading: '.$path);
            }

            try {
                for ($chunkNumber = 1; $chunkNumber <= $totalChunks; $chunkNumber++) {
                    $tmpPath = tempnam(sys_get_temp_dir(), 'notifier_chunk_');

                    if ($tmpPath === false) {
                        throw new RuntimeException('Failed to create temporary file for chunk');
                    }

                    $tmpHandle = fopen($tmpPath, 'wb');

                    if ($tmpHandle === false) {
                        @unlink($tmpPath);
                        throw new RuntimeException('Failed to open temporary chunk file for writing');
                    }

                    $bytesCopied = stream_copy_to_stream($handle, $tmpHandle, $chunkSize);
                    fclose($tmpHandle);

                    if ($bytesCopied === false || $bytesCopied === 0) {
                        @unlink($tmpPath);
                        throw new RuntimeException("Failed to write chunk {$chunkNumber} to temporary file");
                    }

                    try {
                        $this->sendChunk($uploadId, $chunkNumber, $tmpPath);
                    } finally {
                        @unlink($tmpPath);
                    }

                    $logger->info("➡️ chunk {$chunkNumber}/{$totalChunks} sent", $this->logContext('send_chunk', [
                        'upload_id' => $uploadId,
                        'chunk_number' => $chunkNumber,
                        'total_chunks' => $totalChunks,
                    ]));
                }
            } finally {
                fclose($handle);
            }

            // Phase 3: Finalize
            $this->finalizeUpload($uploadId);

            $logger->info('✅ chunked upload finalized', $this->logContext('finalize', ['upload_id' => $uploadId]));
        } finally {
            $this->api->clearRequestId();
            $this->requestId = null;
        }
    }

    private function initUpload(
        string $backupType,
        string $filename,
        int $fileSize,
        int $totalChunks,
        string $checksum,
    ): string {
        $response = $this->api->post('/uploads/init', [
            'backup_type' => $backupType,
            'filename' => $filename,
            'total_size' => $fileSize,
            'total_chunks' => $totalChunks,
            'checksum' => $checksum,
        ], 30);

        if (! $response->successful()) {
            $details = $this->api->errorDetails($response);
            throw new RuntimeException(
                'Failed to initialize upload: HTTP '.$response->status().' - '.$details['message'].$this->failureSuffix($details)
            );
        }

        $uploadId = $response->json('upload_id');

        if (empty($uploadId)) {
            throw new RuntimeException('Server did not return an upload_id');
        }

        return $uploadId;
    }

    private function sendChunk(
        string $uploadId,
        int $chunkNumber,
        string $chunkPath,
        int $maxAttempts = 3,
        int $retryDelayMs = 2000,
    ): void {
        $logger = $this->notifierLogger->get();
        $lastException = null;
        $path = '/uploads/'.$uploadId.'/chunks/'.$chunkNumber;
        $chunkChecksum = hash_file('sha256', $chunkPath);

        if ($chunkChecksum === false) {
            throw new RuntimeException("Failed to compute checksum for chunk {$chunkNumber}");
        }

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $stream = fopen($chunkPath, 'rb');

                if ($stream === false) {
                    throw new RuntimeException("Failed to open chunk file for reading: {$chunkPath}");
                }

                $response = $this->api->attachStream(
                    $path,
                    'chunk',
                    $stream,
                    'chunk_'.$chunkNumber,
                    ['X-Chunk-Checksum' => $chunkChecksum],
                    120,
                );

                if ($response->successful()) {
                    return;
                }

                $details = $this->api->errorDetails($response);

                // Retry 429 (rate limited) - it's transient, not a client mistake
                if ($response->status() === 429) {
                    $lastException = new RuntimeException(
                        "Chunk {$chunkNumber} rate limited: HTTP 429"
                    );
                } elseif ($response->status() >= 400 && $response->status() < 500) {
                    // Don't retry other 4xx errors (client mistakes)
                    throw new RuntimeException(
                        "Chunk {$chunkNumber} rejected: HTTP ".$response->status().' - '.$details['message'].$this->failureSuffix($details)
                    );
                } else {
                    $lastException = new RuntimeException(
                        "Chunk {$chunkNumber} failed: HTTP ".$response->status().' - '.$details['message'].$this->failureSuffix($details)
                    );
                }
            } catch (RuntimeException $e) {
                throw $e;
            } catch (Throwable $e) {
                $lastException = $e;
            }

            if ($attempt < $maxAttempts) {
                $logger->warning("⚠️ chunk {$chunkNumber} attempt {$attempt} failed, retrying...", $this->logContext('send_chunk', [
                    'upload_id' => $uploadId,
                    'chunk_number' => $chunkNumber,
                    'attempt' => $attempt,
                    'error' => $lastException->getMessage(),
                ]));
                usleep($retryDelayMs * 1000);
            }
        }

        throw $lastException ?? new RuntimeException("Chunk {$chunkNumber} failed after {$maxAttempts} attempts");
    }

    private function finalizeUpload(string $uploadId): void
    {
        $response = $this->api->post('/uploads/'.$uploadId.'/finalize', [], 60);

        if (! $response->successful()) {
            $details = $this->api->errorDetails($response);
            throw new RuntimeException(
                'Failed to finalize upload: HTTP '.$response->status().' - '.$details['message'].$this->failureSuffix($details)
            );
        }

        // Async path (server v3+ returns 202 + status_url) - poll until terminal.
        // Sync path (older server returns 200/201 with the result inline) - done.
        if ($response->status() === 202) {
            $statusUrl = $response->json('status_url');

            if (! is_string($statusUrl) || $statusUrl === '') {
                throw new RuntimeException('Server returned 202 without status_url');
            }

            // The secret token is about to be attached to status_url. Never send it
            // anywhere but the configured, HTTPS backup origin - the rest of the
            // package enforces the same HTTPS-only invariant (see NotifierApiClient).
            $this->assertTrustedStatusUrl($statusUrl, $this->api->baseUrl());

            $this->waitForCompletion($statusUrl, $uploadId);
        }
    }

    /**
     * Poll the status endpoint until the upload reaches a terminal state.
     * Throws on `failed` (with the server-supplied reason) or on timeout.
     */
    private function waitForCompletion(
        string $statusUrl,
        string $uploadId,
        int $maxWaitSeconds = 1800,
        int $pollIntervalSeconds = 5,
    ): void {
        $logger = $this->notifierLogger->get();
        $deadline = time() + $maxWaitSeconds;
        $consecutiveErrors = 0;

        while (time() < $deadline) {
            sleep($pollIntervalSeconds);

            try {
                $response = $this->api->getAbsolute($statusUrl, 30);
            } catch (Throwable $e) {
                $consecutiveErrors++;
                $logger->warning("⚠️ status poll failed (attempt {$consecutiveErrors})", $this->logContext('poll_status', [
                    'upload_id' => $uploadId,
                    'attempt' => $consecutiveErrors,
                    'error' => $e->getMessage(),
                ]));

                if ($consecutiveErrors >= 5) {
                    throw new RuntimeException(
                        "Status polling failed {$consecutiveErrors} times in a row: ".$e->getMessage(),
                    );
                }

                continue;
            }

            if (! $response->successful()) {
                $consecutiveErrors++;

                if ($consecutiveErrors >= 5) {
                    $details = $this->api->errorDetails($response);
                    throw new RuntimeException(
                        'Status polling kept returning HTTP '.$response->status().' - '.$details['message'].$this->failureSuffix($details),
                    );
                }

                continue;
            }

            $consecutiveErrors = 0;

            $status = $response->json('status');
            $isTerminal = (bool) $response->json('is_terminal');

            $logger->info("➡️ upload status: {$status}", $this->logContext('poll_status', [
                'upload_id' => $uploadId,
                'status' => $status,
            ]));

            if (! $isTerminal) {
                continue;
            }

            if ($status === 'completed') {
                return;
            }

            // failure_reason is server-supplied and ends up in logs; strip control
            // characters and cap its length to avoid log injection / unbounded growth.
            $reason = (string) ($response->json('failure_reason') ?: 'unknown');
            $reason = mb_substr((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $reason), 0, 500);
            throw new RuntimeException("Backup upload failed on server: {$reason}");
        }

        throw new RuntimeException(
            "Backup upload did not finalize within {$maxWaitSeconds}s - server may still be processing it. Check the dashboard.",
        );
    }

    /**
     * Base log context for the run: the stage and the correlation id, merged with
     * per-call extras. `request_id` matches the X-Request-Id sent to the server,
     * so `grep <request_id>` returns the whole run on both sides.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function logContext(string $stage, array $extra = []): array
    {
        return array_merge([
            'stage' => $stage,
            'request_id' => $this->requestId,
        ], $extra);
    }

    /**
     * A " (error_id=..., request_id=...)" suffix built only from the server's
     * sanitized ids, appended to a failure message so it surfaces in the CLI
     * output and the error log. Empty against an old server that returns no ids
     * (message stays byte-for-byte as before). The token is never part of these.
     *
     * @param  array{message: string, error_id: ?string, request_id: ?string, type: ?string}  $details
     */
    private function failureSuffix(array $details): string
    {
        $parts = [];

        if ($details['error_id'] !== null) {
            $parts[] = 'error_id='.$details['error_id'];
        }

        if ($details['request_id'] !== null) {
            $parts[] = 'request_id='.$details['request_id'];
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    /**
     * Reject a server-supplied status_url that does not share the configured
     * backup origin. The token is a long-lived shared secret; without this guard
     * a tampered/misconfigured finalize response could redirect it to a cleartext
     * or attacker-controlled host (the GET also runs with redirects disabled).
     */
    private function assertTrustedStatusUrl(string $statusUrl, string $baseUrl): void
    {
        $base = parse_url($baseUrl);
        $target = parse_url($statusUrl);

        if (! is_array($target) || ($target['scheme'] ?? null) !== 'https') {
            throw new RuntimeException('Refusing to poll non-HTTPS status_url: '.$statusUrl);
        }

        $baseHost = mb_strtolower($base['host'] ?? '');
        $targetHost = mb_strtolower($target['host'] ?? '');

        if ($targetHost === '' || $targetHost !== $baseHost) {
            throw new RuntimeException('Refusing to poll status_url on unexpected host: '.$statusUrl);
        }

        // Treat the implicit HTTPS port (443) and an explicit :443 as equivalent.
        if (($base['port'] ?? 443) !== ($target['port'] ?? 443)) {
            throw new RuntimeException('Refusing to poll status_url on unexpected port: '.$statusUrl);
        }
    }
}
