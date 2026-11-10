<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip;

use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\Zip\Concerns\GuardsExtractionSize;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

final readonly class PhpZipExtractor implements ZipExtractorInterface
{
    use GuardsExtractionSize;

    public function __construct(
        private NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * libzip before 1.9 trusts the CRC field of a WinZip AE-2 entry from 20 bytes
     * up, but 7-Zip always writes that field as 0. Such an entry decrypts fine and
     * then fails with "CRC error" at its last byte, so once it has been
     * authenticated it is read exactly to its declared size and no further.
     *
     * @param  array<string, mixed>  $stat  A ZipArchive::statIndex() result.
     */
    public static function isUnverifiableByLibzip(array $stat, string $libzipVersion): bool
    {
        return version_compare($libzipVersion, '1.9.0', '<')
            && $stat['encryption_method'] !== ZipArchive::EM_NONE
            && $stat['crc'] === 0
            && $stat['size'] >= 20;
    }

    public function extract(string $zipPath, string $destination, string $password, bool $sealVerified = false): int
    {
        $logger = $this->notifierLogger->get();

        $logger->info('➡️ using PHP ZipArchive strategy for extraction');

        if (! is_file($zipPath)) {
            throw new RuntimeException('Archive not found at: '.$zipPath);
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);

        if ($opened !== true) {
            throw new RuntimeException('Unable to open archive '.$zipPath.' (ZipArchive code '.$opened.').');
        }

        if ($password !== '' && ! $zip->setPassword($password)) {
            $zip->close();

            throw new RuntimeException('Unable to set archive password.');
        }

        try {
            if (! $sealVerified) {
                $this->assertNamesPrintable($zip);
            }

            // When a backup password is configured, EVERY entry must be encrypted.
            // ZipArchive reads an unencrypted entry regardless of setPassword().
            if ($password !== '') {
                $this->assertEveryEntryEncrypted($zip);
            }

            // Without a verified seal, each entry has to prove itself.
            if ($password !== '' && ! $sealVerified) {
                $this->assertEntriesAuthentic($zip, $password);
            }
        } catch (RuntimeException $e) {
            $zip->close();

            throw $e;
        }

        File::ensureDirectoryExists($destination);

        $root = $this->realDirectory($destination);
        $written = 0;

        try {
            // Bound the extraction before writing anything: sum the declared
            // uncompressed sizes and refuse a decompression bomb / disk-fill.
            // (Inside the try so the finally still closes $zip if the guard throws.)
            $totalUncompressed = 0;
            $totalCompressed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if (is_array($stat)) {
                    $totalUncompressed += (int) $stat['size'];
                    $totalCompressed += (int) $stat['comp_size'];
                }
            }

            $this->guardExtractedSize($totalUncompressed, $destination, $totalCompressed);

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }

                $target = $this->safeTarget($root, $name);

                File::ensureDirectoryExists(dirname($target));

                // Stream the entry rather than extractTo(), so a wrong password
                // surfaces as a read failure we can report clearly.
                $stream = $zip->getStreamIndex($i);

                if ($stream === false) {
                    throw new RuntimeException(
                        'Unable to read "'.$name.'" from the archive. The password is probably wrong or the archive is corrupt.'
                    );
                }

                $out = fopen($target, 'wb');

                if ($out === false) {
                    fclose($stream);

                    throw new RuntimeException('Unable to write extracted file: '.$target);
                }

                $entry = $zip->statIndex($i);
                $declaredBytes = (int) ($entry['size'] ?? 0);

                // One byte over the declared size, so an entry that holds more than
                // it declared is noticed and stops here instead of filling the disk.
                $limit = is_array($entry) && self::isUnverifiableByLibzip($entry, ZipArchive::LIBZIP_VERSION)
                    ? $declaredBytes
                    : $declaredBytes + 1;

                $this->copyVerified($stream, $out, $name, $declaredBytes, $limit);

                $written++;
            }
        } finally {
            $zip->close();
        }

        return $written;
    }

    /**
     * Copy one entry and verify it arrived whole. PHP reports a failed read as a
     * mere warning, so the result is checked here instead of depending on how the
     * host application treats warnings.
     *
     * @param  resource  $stream
     * @param  resource  $out
     */
    private function copyVerified($stream, $out, string $name, int $expectedBytes, int $limit): void
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            $copied = stream_copy_to_stream($stream, $out, $limit);
        } finally {
            restore_error_handler();
            fclose($stream);
            fclose($out);
        }

        if ($copied !== $expectedBytes) {
            throw new RuntimeException(
                'Failed to extract "'.$name.'" ('.($warning ?? 'copied '.var_export($copied, true).' of '.$expectedBytes.' bytes').'). '
                .'The archive is corrupt or was tampered with, or the file could not be written to disk.'
            );
        }
    }

    /**
     * Refuse the archive unless every file entry is encrypted. Reads statIndex()
     * for each entry and rejects any with EM_NONE, so a plaintext (attacker-made)
     * archive cannot slip past the configured password.
     */
    private function assertEveryEntryEncrypted(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === false || str_ends_with($name, '/')) {
                continue;
            }

            $stat = $zip->statIndex($i);
            $method = is_array($stat) ? $stat['encryption_method'] : ZipArchive::EM_NONE;

            if ($method === ZipArchive::EM_NONE) {
                throw new RuntimeException(
                    'Refusing to restore: archive entry "'.$name.'" is not encrypted, but a backup password '
                    .'is configured. The archive is corrupt or has been substituted.'
                );
            }
        }
    }

    private function assertNamesPrintable(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            if (preg_match(self::UNPRINTABLE_NAME, (string) $zip->getNameIndex($i)) === 1) {
                throw new RuntimeException('Refusing to restore: an archive entry has control characters in its name.');
            }
        }
    }

    /**
     * Check every entry's WinZip AES authentication code against its raw packed
     * bytes, before anything is decrypted or written.
     */
    private function assertEntriesAuthentic(ZipArchive $zip, string $password): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || str_ends_with($stat['name'], '/')) {
                continue;
            }

            if ($stat['encryption_method'] !== ZipArchive::EM_AES_256) {
                throw new RuntimeException('Refusing to restore: archive entry "'.$stat['name'].'" is not AES-256 encrypted.');
            }

            // An entry declared empty has no content to prove; the copy refuses one that holds data.
            if ($stat['size'] !== 0) {
                $this->assertEntryAuthentic($zip, $i, $stat['name'], $password, (int) $stat['comp_size']);
            }
        }
    }

    private function assertEntryAuthentic(ZipArchive $zip, int $index, string $name, string $password, int $packedBytes): void
    {
        $saltBytes = 16;
        $verifierBytes = 2;
        $authCodeBytes = 10;
        $ciphertextBytes = $packedBytes - $saltBytes - $verifierBytes - $authCodeBytes;

        $failure = new RuntimeException(
            'Refusing to restore: archive entry "'.$name.'" failed authentication. The archive is corrupt or was tampered with.'
        );

        $raw = $ciphertextBytes < 0 ? false : $zip->getStreamIndex($index, ZipArchive::FL_ENCRYPTED);

        if ($raw === false) {
            throw $failure;
        }

        try {
            $header = (string) stream_get_contents($raw, $saltBytes + $verifierBytes);

            if (mb_strlen($header, '8bit') !== $saltBytes + $verifierBytes) {
                throw $failure;
            }

            // 32 bytes of AES key, 32 of HMAC key and the 2-byte password verifier.
            $keys = hash_pbkdf2('sha1', $password, mb_substr($header, 0, $saltBytes, '8bit'), 1000, 66, true);

            if (! hash_equals(mb_substr($keys, 64, $verifierBytes, '8bit'), mb_substr($header, $saltBytes, $verifierBytes, '8bit'))) {
                throw new RuntimeException(
                    'Refusing to restore: the password does not fit archive entry "'.$name.'" '
                    .'(check NOTIFIER_BACKUP_PASSWORD matches the one used when the backup was created).'
                );
            }

            $mac = hash_init('sha1', HASH_HMAC, mb_substr($keys, 32, 32, '8bit'));

            if (hash_update_stream($mac, $raw, $ciphertextBytes) !== $ciphertextBytes) {
                throw $failure;
            }

            $authCode = (string) stream_get_contents($raw, $authCodeBytes);

            if (! hash_equals(mb_substr(hash_final($mac, true), 0, $authCodeBytes, '8bit'), $authCode)) {
                throw $failure;
            }
        } finally {
            fclose($raw);
        }
    }

    /**
     * Resolve an archive entry to an absolute path inside $root, rejecting
     * absolute paths and ../ traversal (zip-slip).
     */
    private function safeTarget(string $root, string $name): string
    {
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:/#', $normalized) === 1) {
            throw new RuntimeException('Refusing to extract absolute path from archive: '.$name);
        }

        $segments = [];

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new RuntimeException('Refusing to extract path escaping the destination: '.$name);
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new RuntimeException('Refusing to extract empty path from archive: '.$name);
        }

        return $root.'/'.implode('/', $segments);
    }

    private function realDirectory(string $path): string
    {
        $real = realpath($path);

        if ($real === false) {
            throw new RuntimeException('Destination directory does not exist: '.$path);
        }

        return mb_rtrim(str_replace('\\', '/', $real), '/');
    }
}
