<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Pulls this site's own backups back down from the control plane.
 *
 * Restore is authenticated with `notifier.restore_token`, NOT the backup code:
 * the credential that ships backups up must not also be able to pull them back
 * down, so a leaked backup code cannot become a data-exfiltration path. An
 * install with no restore token configured cannot restore at all.
 *
 * This service only fetches and applies. Confirmation, production guards and
 * safety snapshots are the caller's (command's) responsibility.
 */
final class NotifierRestoreService
{
    public function __construct(
        private readonly NotifierApiClient $api,
        private readonly NotifierLoggerService $notifierLogger,
        private readonly ZipExtractorInterface $zipExtractor,
        private readonly DatabaseImporterInterface $databaseImporter,
    ) {}

    /**
     * The configured restore credential.
     *
     * @throws RuntimeException When restore is not enabled for this install.
     */
    public function token(): string
    {
        $token = config('notifier.restore_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException(
                'Restore is not enabled for this site (set NOTIFIER_RESTORE_TOKEN). '
                .'The restore token is issued separately from the backup code.'
            );
        }

        return $token;
    }

    /**
     * List the backups available on the server, newest first.
     *
     * @return list<array{id: int, type: string, name: string, size: int|null, created_at: string|null}>
     *
     * @throws RuntimeException When the server rejects or cannot answer the request.
     */
    public function available(BackupTypeEnum $type): array
    {
        $response = $this->api->getWith('/backups?type='.$type->value, $this->token());

        if (! $response->successful()) {
            throw new RuntimeException(
                'Could not list backups: HTTP '.$response->status().' - '.$this->api->formatError($response)
            );
        }

        /** @var list<array{id: int, type: string, name: string, size: int|null, created_at: string|null}> $data */
        $data = $response->json('data') ?? [];

        return $data;
    }

    /**
     * Resolve which backup to fetch: an explicit id, or the newest of its type.
     *
     * @return array{id: int, type: string, name: string, size: int|null, created_at: string|null}
     *
     * @throws RuntimeException When nothing matches.
     */
    public function resolve(BackupTypeEnum $type, ?int $id = null): array
    {
        $available = $this->available($type);

        if ($available === []) {
            throw new RuntimeException('The server has no '.$type->value.' backup for this site.');
        }

        if ($id === null) {
            return $available[0];
        }

        foreach ($available as $backup) {
            if ($backup['id'] === $id) {
                return $backup;
            }
        }

        throw new RuntimeException('Backup #'.$id.' was not found among this site\'s '.$type->value.' backups.');
    }

    /**
     * Download one backup archive to a local path.
     *
     * @throws RuntimeException When the download fails or lands empty.
     */
    public function download(int $id, string $destination): string
    {
        $logger = $this->notifierLogger->get();

        File::ensureDirectoryExists(dirname($destination));

        $response = $this->api->downloadTo('/backups/'.$id.'/download', $destination, $this->token());

        if (! $response->successful()) {
            // The sink already created the file; a 4xx body would otherwise be
            // left on disk masquerading as an archive.
            File::delete($destination);

            throw new RuntimeException(
                'Backup download failed: HTTP '.$response->status().' - '.$this->api->formatError($response)
            );
        }

        clearstatcache(true, $destination);

        // A truncated transfer yields a small or empty file that would later
        // fail deep inside the unzip with a confusing message.
        if (! is_file($destination) || filesize($destination) < 100) {
            File::delete($destination);

            throw new RuntimeException('Downloaded archive is empty or truncated.');
        }

        $logger->info('➡️ backup archive downloaded', [
            'id' => $id,
            'bytes' => filesize($destination),
        ]);

        return $destination;
    }

    /**
     * Extract a downloaded archive into a working directory.
     *
     * @return string Path to the extraction directory.
     */
    public function extract(string $archivePath, string $workingDirectory): string
    {
        $password = (string) config('notifier.backup_zip_password');

        File::ensureDirectoryExists($workingDirectory);

        $this->zipExtractor->extract($archivePath, $workingDirectory, $password);

        return $workingDirectory;
    }

    /**
     * Import the single .sql file produced by a database backup.
     *
     * @throws RuntimeException When the archive does not contain exactly one dump.
     */
    public function importDatabase(string $extractedDirectory): void
    {
        $dumps = glob($extractedDirectory.'/*.sql') ?: [];

        if ($dumps === []) {
            throw new RuntimeException('No .sql dump found in the extracted archive.');
        }

        if (count($dumps) > 1) {
            throw new RuntimeException('Expected exactly one .sql dump, found '.count($dumps).'.');
        }

        $this->databaseImporter->import($dumps[0]);
    }

    /**
     * Copy the extracted storage tree over the live storage path.
     *
     * Additive by design: existing files are overwritten, but nothing already
     * present is deleted. A restore must never silently destroy files that the
     * backup simply predates.
     *
     * @return int Number of files written.
     */
    public function restoreStorage(string $extractedDirectory, string $storagePath): int
    {
        File::ensureDirectoryExists($storagePath);

        $written = 0;

        foreach (File::allFiles($extractedDirectory) as $file) {
            $relative = $file->getRelativePathname();
            $target = mb_rtrim($storagePath, '/').'/'.$relative;

            File::ensureDirectoryExists(dirname($target));
            File::copy($file->getPathname(), $target);

            $written++;
        }

        return $written;
    }
}
