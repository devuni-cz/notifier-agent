<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip;

use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CliZipExtractor implements ZipExtractorInterface
{
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

        File::ensureDirectoryExists($destination);

        // Password is provided via stdin (not argv) to prevent exposure via
        // /proc/<pid>/cmdline or `ps` output on shared hosts, matching how
        // CliZipCreator writes the archive.
        $command = [
            '7z', 'x',
            '-p',
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

        return $this->countExtracted($destination);
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
