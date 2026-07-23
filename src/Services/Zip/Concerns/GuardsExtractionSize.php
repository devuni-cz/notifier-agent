<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip\Concerns;

use RuntimeException;

/**
 * Shared decompression-bomb / disk-fill guard for the ZIP extractors.
 *
 * The archive is downloaded from an untrusted control plane, so its declared
 * uncompressed size is checked BEFORE a single byte is written: a crafted (or
 * accidentally huge) archive could otherwise fill the disk and DoS the host
 * before the SQL-directive / webshell guards downstream ever run.
 */
trait GuardsExtractionSize
{
    /**
     * Pure size-policy decision. $free is the destination volume's free bytes,
     * or false when it could not be determined (then the disk check is skipped).
     *
     *
     * @throws RuntimeException When any configured ceiling is exceeded.
     */
    protected static function assertSizeFits(
        int $totalUncompressed,
        int $totalCompressed,
        float|false $free,
        int $cap,
        int $margin,
        int $maxRatio,
    ): void {
        if ($cap > 0 && $totalUncompressed > $cap) {
            throw new RuntimeException(
                'Refusing to restore: the archive expands to '.$totalUncompressed.' bytes, over the '
                .$cap.'-byte limit (NOTIFIER_RESTORE_MAX_EXTRACTED_BYTES).'
            );
        }

        if ($maxRatio > 0 && $totalCompressed > 0 && $totalUncompressed / $totalCompressed > $maxRatio) {
            throw new RuntimeException(
                'Refusing to restore: the archive compression ratio ('.(int) ($totalUncompressed / $totalCompressed)
                .':1) exceeds the '.$maxRatio.':1 limit (NOTIFIER_RESTORE_MAX_COMPRESSION_RATIO) - possible zip bomb.'
            );
        }

        if ($free !== false && $totalUncompressed + $margin > $free) {
            throw new RuntimeException(
                'Refusing to restore: extracting needs '.$totalUncompressed.' bytes but only '.((int) $free)
                .' bytes are free on the destination volume (keeping a '.$margin.'-byte margin).'
            );
        }
    }

    /**
     * Reject the extraction if it would blow past the configured ceilings.
     * Reads config + measures free disk space, then delegates the decision to
     * the pure {@see self::assertSizeFits()} so the logic is unit-testable
     * without a real archive or volume.
     *
     * @throws RuntimeException When the archive is too large to extract safely.
     */
    protected function guardExtractedSize(int $totalUncompressed, string $destination, int $totalCompressed = 0): void
    {
        $cap = (int) config('notifier.restore_max_extracted_bytes', 0);
        $margin = (int) config('notifier.restore_disk_free_margin_bytes', 0);
        $maxRatio = (int) config('notifier.restore_max_compression_ratio', 0);

        $free = @disk_free_space($destination);

        self::assertSizeFits($totalUncompressed, $totalCompressed, $free, $cap, $margin, $maxRatio);
    }
}
