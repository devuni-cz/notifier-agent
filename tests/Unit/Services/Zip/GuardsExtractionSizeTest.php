<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Zip\Concerns\GuardsExtractionSize;

/*
|--------------------------------------------------------------------------
| The pure size-policy decision behind the zip-bomb / disk-fill guard. Driven
| directly with synthetic totals + free-space so every branch is testable
| without a real archive or volume.
|--------------------------------------------------------------------------
*/

function sizeGuard(): object
{
    return new class
    {
        use GuardsExtractionSize;

        public function fits(int $uncompressed, int $compressed, float|false $free, int $cap, int $margin, int $ratio): void
        {
            self::assertSizeFits($uncompressed, $compressed, $free, $cap, $margin, $ratio);
        }
    };
}

it('accepts an archive within every limit', function () {
    sizeGuard()->fits(1_000, 500, 1_000_000_000, 10_000, 0, 0);
    expect(true)->toBeTrue();
});

it('rejects an archive over the absolute uncompressed cap', function () {
    expect(fn () => sizeGuard()->fits(20_000, 1_000, false, 10_000, 0, 0))
        ->toThrow(RuntimeException::class, 'over the 10000-byte limit');
});

it('disables the absolute cap when it is 0', function () {
    sizeGuard()->fits(999_999_999, 1, false, 0, 0, 0);
    expect(true)->toBeTrue();
});

it('rejects an archive whose compression ratio exceeds the configured limit', function () {
    // 100000 / 100 = 1000:1, over a 10:1 cap. Absolute cap disabled (0) so the
    // ratio guard is what trips.
    expect(fn () => sizeGuard()->fits(100_000, 100, false, 0, 0, 10))
        ->toThrow(RuntimeException::class, 'compression ratio');
});

it('ignores the ratio guard when it is 0', function () {
    sizeGuard()->fits(100_000, 1, false, 500_000, 0, 0);
    expect(true)->toBeTrue();
});

it('rejects an archive that would not fit in the free space minus the margin', function () {
    // needs 1_000_000 + 200_000 margin = 1_200_000, only 1_000_000 free.
    expect(fn () => sizeGuard()->fits(1_000_000, 10, 1_000_000, 0, 200_000, 0))
        ->toThrow(RuntimeException::class, 'free on the destination');
});

it('skips the disk check when free space is undeterminable (false)', function () {
    sizeGuard()->fits(1_000_000_000, 10, false, 0, 200_000, 0);
    expect(true)->toBeTrue();
});
