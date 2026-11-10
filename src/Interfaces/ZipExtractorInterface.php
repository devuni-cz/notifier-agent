<?php

declare(strict_types=1);

namespace Devuni\Notifier\Interfaces;

use RuntimeException;

interface ZipExtractorInterface
{
    /**
     * Control characters in an entry name, which reach the terminal and the
     * filesystem. Refused on any archive that has not been authenticated.
     */
    public const string UNPRINTABLE_NAME = '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/';

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
     * @param  bool  $sealVerified  Whether the whole file already passed BackupSeal, so its entries need no further proof.
     * @return int Number of files written.
     *
     * @throws RuntimeException on a wrong password, a corrupt archive or a traversal attempt.
     */
    public function extract(string $zipPath, string $destination, string $password, bool $sealVerified = false): int;
}
