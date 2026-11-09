<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip;

use Devuni\Notifier\Enums\SealStatusEnum;

/**
 * The outcome of checking an archive's seal. $sealedAt and $coveredLength are
 * set only when the seal itself authenticated, so they can be trusted.
 */
final readonly class SealInspection
{
    public function __construct(
        public SealStatusEnum $status,
        public ?int $sealedAt = null,
        public ?int $coveredLength = null,
    ) {}
}
