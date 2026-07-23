<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip;

use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\Zip\Concerns\GuardsExtractionSize;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

final class PhpZipExtractor implements ZipExtractorInterface
{
    use GuardsExtractionSize;

    public function __construct(
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    public function extract(string $zipPath, string $destination, string $password): int
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

        // When a backup password is configured, EVERY entry must be encrypted.
        // ZipArchive reads an unencrypted entry regardless of setPassword(), so a
        // hostile control plane could otherwise substitute a plaintext archive and
        // bypass the password entirely - the encryption IS the archive's
        // authenticity check, so refuse anything that is not encrypted.
        if ($password !== '') {
            $this->assertEveryEntryEncrypted($zip);
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
                $stream = $zip->getStream($name);

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

                stream_copy_to_stream($stream, $out);
                fclose($stream);
                fclose($out);

                $written++;
            }
        } finally {
            $zip->close();
        }

        return $written;
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
                $zip->close();

                throw new RuntimeException(
                    'Refusing to restore: archive entry "'.$name.'" is not encrypted, but a backup password '
                    .'is configured. The archive is corrupt or has been substituted.'
                );
            }
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
