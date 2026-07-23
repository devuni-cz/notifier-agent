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

final class CliZipExtractor implements ZipExtractorInterface
{
    use GuardsExtractionSize;

    public function __construct(
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        $process = new Process(['7z', '--help']);
        $process->run();

        return $process->isSuccessful();
    }

    public function extract(string $zipPath, string $destination, string $password): int
    {
        $logger = $this->notifierLogger->get();

        $logger->info('➡️ using CLI 7z strategy for extraction');

        if (! is_file($zipPath)) {
            throw new RuntimeException('Archive not found at: '.$zipPath);
        }

        // When a password is configured, every file entry must be encrypted -
        // 7z would silently extract a plaintext (attacker-substituted) archive and
        // ignore the password, so the encryption is what authenticates the archive.
        if ($password !== '') {
            $this->assertEveryEntryEncrypted($zipPath);
        }

        File::ensureDirectoryExists($destination);

        // Bound the extraction before writing anything: sum the declared
        // uncompressed sizes and refuse a decompression bomb / disk-fill.
        [$uncompressed, $compressed] = $this->extractedSizeBytes($zipPath);
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
        $this->assertNoUnsafePaths($destination);

        return $this->countExtracted($destination);
    }

    /**
     * Sum the declared uncompressed + compressed sizes of every file entry via
     * `7z l -slt`. Runs unconditionally (unlike the encryption check, which only
     * runs with a password), so it needs its own listing pass.
     *
     * @return array{0: int, 1: int} [uncompressed, compressed] bytes
     */
    private function extractedSizeBytes(string $zipPath): array
    {
        $list = new Process(['7z', 'l', '-slt', $zipPath]);
        $list->setInput('');
        $list->run();

        if (! $list->isSuccessful()) {
            throw new RuntimeException(
                'Unable to inspect archive size (7z l): '.($list->getErrorOutput() ?: $list->getOutput())
            );
        }

        $output = $list->getOutput();
        $marker = mb_strpos($output, '----------');
        $entriesText = $marker === false ? $output : mb_substr($output, $marker);

        $blocks = preg_split('/\R\R+/', mb_trim($entriesText)) ?: [];
        $uncompressed = 0;
        $compressed = 0;

        foreach ($blocks as $block) {
            if (! str_contains($block, 'Path = ')) {
                continue;
            }

            // Anchored /m so "Packed Size = " never satisfies "^Size = ".
            if (preg_match('/^Size = (\d+)/m', $block, $m) === 1) {
                $uncompressed += (int) $m[1];
            }

            if (preg_match('/^Packed Size = (\d+)/m', $block, $m) === 1) {
                $compressed += (int) $m[1];
            }
        }

        return [$uncompressed, $compressed];
    }

    /**
     * Reject the archive unless every file entry is AES-encrypted, using
     * `7z l -slt`. A directory entry (Folder = +) carries no encryption and is
     * skipped; a plaintext file entry (Encrypted = -) aborts the restore.
     */
    private function assertEveryEntryEncrypted(string $zipPath): void
    {
        $list = new Process(['7z', 'l', '-slt', $zipPath]);
        // Never block on a password prompt while merely listing.
        $list->setInput('');
        $list->run();

        if (! $list->isSuccessful()) {
            throw new RuntimeException(
                'Unable to inspect archive encryption (7z l): '.($list->getErrorOutput() ?: $list->getOutput())
            );
        }

        $output = $list->getOutput();
        $marker = mb_strpos($output, '----------');
        $entriesText = $marker === false ? $output : mb_substr($output, $marker);

        $blocks = preg_split('/\R\R+/', mb_trim($entriesText)) ?: [];
        $fileEntries = 0;

        foreach ($blocks as $block) {
            if (! str_contains($block, 'Path = ')) {
                continue;
            }

            // Folder entries have no encryption of their own.
            if (preg_match('/^Folder = \+/m', $block) === 1) {
                continue;
            }

            $fileEntries++;

            if (preg_match('/^Encrypted = \+/m', $block) !== 1) {
                throw new RuntimeException(
                    'Refusing to restore: the archive contains an unencrypted entry, but a backup password '
                    .'is configured. The archive is corrupt or has been substituted.'
                );
            }
        }

        if ($fileEntries === 0) {
            throw new RuntimeException('Refusing to restore: the archive contains no file entries to verify.');
        }
    }

    /**
     * Abort (and wipe the extraction) if any extracted path is a symlink or
     * resolves outside $destination - the classic zip-slip / symlink escapes that
     * an external 7z binary may not block.
     */
    private function assertNoUnsafePaths(string $destination): void
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
