<?php

declare(strict_types=1);

namespace Devuni\Notifier\Interfaces;

use RuntimeException;

interface ZipExtractorInterface
{
    /**
     * Whether the underlying extraction strategy is usable on this host.
     */
    public static function isAvailable(): bool;

    /**
     * Extract a password-protected ZIP into a destination directory.
     *
     * Implementations must reject entries whose resolved path would land
     * outside $destination (zip-slip), and must not follow archived symlinks
     * out of the destination.
     *
     * @return int Number of files written.
     *
     * @throws RuntimeException on a wrong password, a corrupt archive or a traversal attempt.
     */
    public function extract(string $zipPath, string $destination, string $password): int;
}
