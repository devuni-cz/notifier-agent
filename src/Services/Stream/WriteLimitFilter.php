<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Stream;

use php_user_filter;
use StreamBucket;

/**
 * Write-side stream filter that enforces a {@see WriteLimit}.
 *
 * Attach it through {@see WriteLimit::attachTo()}.
 */
final class WriteLimitFilter extends php_user_filter
{
    public const string NAME = 'notifier.write_limit';

    private WriteLimit $limit;

    public function onCreate(): bool
    {
        if (! $this->params instanceof WriteLimit) {
            return false;
        }

        $this->limit = $this->params;

        return true;
    }

    /**
     * Pass on what fits and drop the rest without counting it as consumed.
     *
     * fwrite() then returns 0 rather than false. curl ends a transfer as soon as
     * its write callback reports a short write, whereas the exception Guzzle 7
     * raises for a false return does not stop curl from reading the response.
     *
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while (($bucket = stream_bucket_make_writeable($in)) instanceof StreamBucket) {
            if ($this->limit->take($bucket->datalen)) {
                $consumed += $bucket->datalen;
                stream_bucket_append($out, $bucket);
            }
        }

        return PSFS_PASS_ON;
    }
}
