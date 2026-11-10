<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip;

use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\Zip\Concerns\GuardsExtractionSize;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

final readonly class CliZipExtractor implements ZipExtractorInterface
{
    use GuardsExtractionSize;

    public function __construct(
        private NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        $process = new Process(['7z', '--help']);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Whether a `7z l -slt` listing shows at least one file and no plaintext one.
     * A directory entry carries no encryption of its own, so it is exempt. Lines
     * are read one at a time: whatever else a listing contains can only add lines
     * and never removes the ones 7z printed for a real entry.
     */
    public static function everyFileEntryEncrypted(string $listing): bool
    {
        $folder = null;
        $encrypted = 0;

        foreach (self::entryLines($listing) as $line) {
            if (preg_match('/^Folder = ([+-])/', $line, $match) === 1) {
                $folder = $match[1];

                continue;
            }

            if (preg_match('/^Encrypted = ([+-])/', $line, $match) !== 1) {
                continue;
            }

            if ($match[1] === '-' && $folder !== '+') {
                return false;
            }

            $encrypted += $match[1] === '+' ? 1 : 0;
            $folder = null;
        }

        return $encrypted > 0;
    }

    /**
     * Sum the declared uncompressed + compressed sizes in a `7z l -slt` listing.
     *
     * @return array{0: int, 1: int} [uncompressed, compressed] bytes
     */
    public static function listedSizes(string $listing): array
    {
        $uncompressed = 0;
        $compressed = 0;

        foreach (self::entryLines($listing) as $line) {
            // Anchored so "Packed Size = " never satisfies "^Size = ".
            if (preg_match('/^Size = (\d+)/', $line, $match) === 1) {
                $uncompressed += (int) $match[1];
            } elseif (preg_match('/^Packed Size = (\d+)/', $line, $match) === 1) {
                $compressed += (int) $match[1];
            }
        }

        return [$uncompressed, $compressed];
    }

    public function extract(string $zipPath, string $destination, string $password, bool $sealVerified = false): int
    {
        $logger = $this->notifierLogger->get();

        $logger->info('➡️ using CLI 7z strategy for extraction');

        if (! is_file($zipPath)) {
            throw new RuntimeException('Archive not found at: '.$zipPath);
        }

        // When a password is configured, every file entry must be encrypted -
        // 7z would silently extract a plaintext archive and ignore the password.
        // A verified seal already covers this, and a genuine backup must not be
        // refused because of how one of its file names prints in a listing.
        if ($password !== '' && ! $sealVerified && ! self::everyFileEntryEncrypted($this->listing($zipPath))) {
            throw new RuntimeException(
                'Refusing to restore: the archive has an unencrypted entry or no files at all, but a backup password '
                .'is configured. The archive is corrupt or has been substituted.'
            );
        }

        File::ensureDirectoryExists($destination);

        // Bound the extraction before writing anything: sum the declared
        // uncompressed sizes and refuse a decompression bomb / disk-fill.
        [$uncompressed, $compressed] = self::listedSizes($this->listing($zipPath));
        $this->guardExtractedSize($uncompressed, $destination, $compressed);

        // Password is provided via stdin (not argv) to prevent exposure via
        // /proc/<pid>/cmdline or `ps` output on shared hosts, matching how
        // CliZipCreator writes the archive.
        //
        // "-p" is deliberately OMITTED here. 7z only prompts for the password -
        // and therefore reads it from stdin - when no -p switch is present. A
        // bare "-p" means an EMPTY password and 7z never consults stdin, so
        // extracting a real AES-256 archive with it always fails with exit 2.
        // (Creating is different: `7z a -p` does prompt, which is why
        // CliZipCreator can keep its bare -p.)
        $command = [
            '7z', 'x',
            '-y',
            '-o'.$destination,
            $zipPath,
        ];

        $process = new Process($command);
        $process->setTimeout(600);
        $process->setInput($password."\n");
        $process->run();

        if (! $process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            $combined = $stderr.$process->getOutput();

            // 7z reports a wrong password as a generic data error; make it actionable.
            if (str_contains($combined, 'Wrong password') || str_contains($combined, 'Data Error')) {
                throw new RuntimeException(
                    'Extraction failed - the archive password appears to be wrong '
                    .'(check NOTIFIER_BACKUP_PASSWORD matches the one used when the backup was created).'
                );
            }

            throw new RuntimeException(
                'CLI unzip (7z) failed (exit code '.$process->getExitCode().'): '.($stderr ?: $process->getOutput())
            );
        }

        clearstatcache(true, $destination);

        // 7z's own traversal protection varies by version, and it happily restores
        // symlink entries. Verify AFTER extraction that nothing is a symlink and
        // nothing resolved outside the destination (zip-slip), matching the guard
        // the PHP extractor applies inline.
        $this->assertNoUnsafePaths($destination, $sealVerified);

        return $this->countExtracted($destination);
    }

    /**
     * The lines of a `7z l -slt` listing from the first entry on, past the
     * archive-level header.
     *
     * @return list<string>
     */
    private static function entryLines(string $listing): array
    {
        $lines = preg_split('/\R/', $listing) ?: [];

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '----------')) {
                return array_slice($lines, $index + 1);
            }
        }

        return $lines;
    }

    private function listing(string $zipPath): string
    {
        $list = new Process(['7z', 'l', '-slt', $zipPath]);
        // Never block on a password prompt while merely listing.
        $list->setInput('');
        $list->run();

        if (! $list->isSuccessful()) {
            throw new RuntimeException(
                'Unable to inspect the archive (7z l): '.($list->getErrorOutput() ?: $list->getOutput())
            );
        }

        return $list->getOutput();
    }

    /**
     * Abort (and wipe the extraction) if any extracted path is a symlink or
     * resolves outside $destination - the classic zip-slip / symlink escapes that
     * an external 7z binary may not block. An archive that has not been
     * authenticated also may not carry control characters in its names.
     */
    private function assertNoUnsafePaths(string $destination, bool $sealVerified): void
    {
        $root = realpath($destination);

        if ($root === false) {
            return;
        }

        $root = mb_rtrim(str_replace('\\', '/', $root), '/');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destination, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            $path = $entry->getPathname();

            if (! $sealVerified && preg_match(self::UNPRINTABLE_NAME, $entry->getFilename()) === 1) {
                File::deleteDirectory($destination);

                throw new RuntimeException('Refusing to restore: an archive entry has control characters in its name.');
            }

            if (is_link($path)) {
                File::deleteDirectory($destination);

                throw new RuntimeException('Refusing to restore: the archive contains a symlink entry ('.$entry->getFilename().').');
            }

            $real = realpath($path);
            $normalized = $real === false ? null : mb_rtrim(str_replace('\\', '/', $real), '/');

            if ($normalized === null || ($normalized !== $root && ! str_starts_with($normalized, $root.'/'))) {
                File::deleteDirectory($destination);

                throw new RuntimeException('Refusing to restore: an extracted path escaped the destination directory.');
            }
        }
    }

    private function countExtracted(string $destination): int
    {
        if (! is_dir($destination)) {
            return 0;
        }

        $count = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($destination, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if (! $file->isDir()) {
                $count++;
            }
        }

        return $count;
    }
}
