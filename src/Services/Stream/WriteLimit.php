<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Stream;

use RuntimeException;

/**
 * How many more bytes may be written to one stream.
 *
 * The state lives here rather than in {@see WriteLimitFilter} because PHP never
 * hands the filter instance back to the code that attached it, and that code
 * needs to know the limit was hit: not every HTTP handler reports a refused
 * write as an error.
 */
final class WriteLimit
{
    public private(set) bool $exceeded = false;

    private function __construct(private int $remaining) {}

    /**
     * Cap the bytes written to $stream from now on. Reads are not affected.
     *
     * @param  resource  $stream
     *
     * @throws RuntimeException When the limit cannot be enforced on this stream.
     */
    public static function attachTo($stream, int $maxBytes): self
    {
        // Returns false when already registered by an earlier call in this process.
        stream_filter_register(WriteLimitFilter::NAME, WriteLimitFilter::class);

        $limit = new self($maxBytes);

        if (@stream_filter_append($stream, WriteLimitFilter::NAME, STREAM_FILTER_WRITE, $limit) === false) {
            throw new RuntimeException('Could not attach the write limit to the stream.');
        }

        return $limit;
    }

    /**
     * Claim room for $bytes. Once a write is refused every later one is too, even
     * one small enough to fit, because the data would no longer be contiguous.
     */
    public function take(int $bytes): bool
    {
        if ($this->exceeded || $bytes > $this->remaining) {
            $this->exceeded = true;

            return false;
        }

        $this->remaining -= $bytes;

        return true;
    }
}
