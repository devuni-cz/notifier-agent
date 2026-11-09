<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Devuni\Notifier\Services\Zip\BackupSeal;
use Devuni\Notifier\Services\Zip\PhpZipExtractor;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\Finder;

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
final readonly class NotifierRestoreService
{
    public function __construct(
        private NotifierApiClient $api,
        private NotifierLoggerService $notifierLogger,
        private ZipExtractorInterface $zipExtractor,
        private DatabaseImporterInterface $databaseImporter,
        private PhpZipExtractor $phpZipExtractor,
        private BackupSeal $seal,
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
     * @return list<array{id: int, type: string, name: string, size: int|null, checksum: string|null, created_at: string|null}>
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

        $data = $response->json('data');

        if (! is_array($data)) {
            return [];
        }

        // The server is untrusted input here: `id` is later used to build a local
        // filesystem path (storage/app/notifier-restore/{id}) that is deleteDirectory'd
        // in a finally block, so a non-integer id would be a path-traversal /
        // arbitrary-directory-deletion primitive. Validate every row and coerce id
        // to a real int before it can reach any path.
        $backups = [];

        foreach ($data as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT);

            if ($id === false) {
                continue;
            }

            $backups[] = [
                'id' => $id,
                'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
                'name' => $this->printableName($row['name'] ?? null),
                'size' => isset($row['size']) && is_numeric($row['size']) ? (int) $row['size'] : null,
                'checksum' => $this->normalizeChecksum($row['checksum'] ?? null),
                'created_at' => $this->printableDate($row['created_at'] ?? null),
            ];
        }

        return $backups;
    }

    /**
     * Resolve which backup to fetch: an explicit id, or the newest of its type.
     *
     * @return array{id: int, type: string, name: string, size: int|null, checksum: string|null, created_at: string|null}
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
     * When $expectedChecksum is a SHA-256 the server reported, the downloaded
     * bytes are hashed and compared before the file is accepted. This catches a
     * transfer that went wrong; it says nothing about where the archive came
     * from, because the same server supplies the checksum and the file. That is
     * what verify() is for. A null checksum means the server had none to give
     * (pre-checksum backup).
     *
     * @param  string|null  $expectedChecksum  Lower-case SHA-256 hex, or null when unavailable.
     *
     * @throws RuntimeException When the download fails, lands empty, or fails verification.
     */
    public function download(int $id, string $destination, ?string $expectedChecksum = null): string
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

        $this->verifyChecksum($destination, $expectedChecksum, $response->header('X-Backup-Checksum'));

        $logger->info('➡️ backup archive downloaded', [
            'id' => $id,
            'bytes' => filesize($destination),
            'verified' => $expectedChecksum !== null,
        ]);

        return $destination;
    }

    /**
     * A new private directory for one restore run. A fresh name every time means
     * nothing from an interrupted or concurrent run can end up in this one.
     *
     * @throws RuntimeException When it cannot be created.
     */
    public function workDirectory(): string
    {
        $root = storage_path('app/notifier-restore');

        File::ensureDirectoryExists($root);
        $this->removeAbandonedRuns($root);

        $path = $root.'/run-'.bin2hex(random_bytes(8));

        if (! @mkdir($path, 0700)) {
            throw new RuntimeException('Could not create the restore working directory: '.$path);
        }

        return $path;
    }

    /**
     * Apply the restore policy to a downloaded file: its seal, its type, its age
     * and any approval the operator gave for this exact file.
     *
     * @param  bool  $explicitId  Whether the operator chose this backup with --id.
     * @param  string|null  $acceptedDigest  The value of --accept-unverified.
     *
     * @throws RuntimeException When the file may not be restored.
     */
    public function verify(BackupTypeEnum $type, string $archivePath, int $backupId, bool $explicitId, ?string $acceptedDigest): VerifiedArchive
    {
        return VerifiedArchive::establish(
            path: $archivePath,
            type: $type,
            backupId: $backupId,
            explicitId: $explicitId,
            acceptedDigest: $acceptedDigest,
            password: (string) config('notifier.backup_zip_password'),
            backupUrl: (string) config('notifier.backup_url'),
            seal: $this->seal,
            now: now(),
        );
    }

    /**
     * Extract a verified archive into a working directory.
     *
     * A database backup taken on a password-less install is a BARE .sql, not a
     * ZIP, and is staged directly. An archive that could not be authenticated
     * goes through the PHP extractor whenever it is available, so one reader both
     * checks the entries and writes them.
     *
     * @return string Path to the extraction directory.
     */
    public function extract(VerifiedArchive $archive, string $workingDirectory): string
    {
        File::ensureDirectoryExists($workingDirectory);

        if (! $archive->isZip) {
            // Stage it so importDatabase()'s glob('*.sql') finds exactly one dump;
            // SqlDirectiveGuard still runs inside the importer.
            File::copy($archive->path, $workingDirectory.'/backup.sql');

            return $workingDirectory;
        }

        $extractor = ! $archive->sealed && PhpZipExtractor::isAvailable()
            ? $this->phpZipExtractor
            : $this->zipExtractor;

        $extractor->extract($archive->path, $workingDirectory, (string) config('notifier.backup_zip_password'), $archive->sealed);

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
     * Re-import one specific, trusted .sql file - the pre-restore snapshot the
     * command took before overwriting the database. Used to roll back when a
     * restore fails part-way (MySQL cannot do a transactional full-schema
     * restore, so the command-level snapshot IS the atomicity guarantee). Runs
     * through the same guarded importer as a normal restore.
     */
    public function restoreDatabaseFromFile(string $sqlPath): void
    {
        $this->databaseImporter->import($sqlPath);
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

        // It is written into storage/app/public, which the web server serves.
        // Validate EVERY entry before writing anything, so a single .php/.htaccess
        // in the archive aborts the restore instead of dropping code into a live,
        // web-reachable directory.
        $this->assertStorageEntriesSafe($extractedDirectory);

        $written = 0;

        foreach ($this->storageEntries($extractedDirectory) as $file) {
            $relative = $file->getRelativePathname();
            $target = mb_rtrim($storagePath, '/').'/'.$relative;

            File::ensureDirectoryExists(dirname($target));
            File::copy($file->getPathname(), $target);

            $written++;
        }

        return $written;
    }

    /**
     * Move an approved but unauthenticated storage restore into a private
     * directory instead of the web-served one. Nothing is published until a
     * person has looked at it and copied what is needed.
     *
     * @return string The directory the files are in.
     *
     * @throws RuntimeException When an entry is unsafe or the files cannot be moved.
     */
    public function quarantineStorage(string $extractedDirectory, int $backupId): string
    {
        $this->assertStorageEntriesSafe($extractedDirectory);

        $target = storage_path('app/notifier-restore/unverified-storage-'.$backupId.'-'.date('Ymd-His'));

        File::ensureDirectoryExists(dirname($target));

        if (! File::moveDirectory($extractedDirectory, $target)) {
            throw new RuntimeException('Could not move the extracted files to '.$target.'.');
        }

        // (@ - chmod is a no-op on Windows.)
        @chmod($target, 0700);

        return $target;
    }

    /**
     * Refuse an extracted tree that holds anything unsafe for a web-served
     * directory. Public so a dry run reports what a real restore would refuse.
     *
     * @throws RuntimeException When an entry is unsafe to write.
     */
    public function assertStorageEntriesSafe(string $extractedDirectory): void
    {
        foreach ($this->storageEntries($extractedDirectory) as $file) {
            $this->assertSafeStorageEntry($file->getRelativePathname());
        }
    }

    /**
     * Every file in an extracted tree, including dotfiles and version-control
     * directories: an entry left out here would be neither checked nor restored.
     */
    private function storageEntries(string $directory): Finder
    {
        return Finder::create()->files()->ignoreDotFiles(false)->ignoreVCS(false)->in($directory);
    }

    /**
     * A run killed outright never reaches its cleanup and can leave a plaintext
     * dump behind. A day is far longer than any live run takes.
     */
    private function removeAbandonedRuns(string $root): void
    {
        $cutoff = now()->subDay()->getTimestamp();

        foreach (File::glob($root.'/run-*') ?: [] as $directory) {
            if (File::lastModified($directory) < $cutoff) {
                File::deleteDirectory($directory);
            }
        }
    }

    /**
     * Backup names and dates come from the server and go straight to the
     * terminal, so only plain characters get through.
     */
    private function printableName(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        return (string) preg_replace('/[^A-Za-z0-9._ -]/', '_', mb_substr($value, 0, 200));
    }

    private function printableDate(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[0-9TZ:.+ -]{1,40}\z/', $value) === 1 ? $value : null;
    }

    /**
     * Accept only a well-formed SHA-256 hex digest, lower-cased; anything else
     * (including a missing or malformed value from an older/odd server) becomes
     * null and is treated downstream as "cannot verify" rather than trusted.
     */
    private function normalizeChecksum(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/i', $value) !== 1) {
            return null;
        }

        return mb_strtolower($value);
    }

    /**
     * Verify the downloaded archive against the SHA-256 the server reported.
     *
     * The expected hash is taken from the backup listing ($expected); the
     * download response also carries it in a header ($headerChecksum) as a
     * second copy. If both are present they must agree, and the file must hash
     * to that value. A wrong hash deletes the file and aborts.
     *
     * @throws RuntimeException When the archive fails verification.
     */
    private function verifyChecksum(string $path, ?string $expected, ?string $headerChecksum): void
    {
        $header = $this->normalizeChecksum($headerChecksum);

        // If the listing gave no checksum, fall back to the header copy so a
        // direct download (no prior list call) can still be verified.
        $expected ??= $header;

        if ($expected === null) {
            return;
        }

        if ($header !== null && ! hash_equals($expected, $header)) {
            File::delete($path);

            throw new RuntimeException(
                'Refusing to restore: the server gave two disagreeing checksums for this backup.'
            );
        }

        $actual = hash_file('sha256', $path);

        if ($actual === false || ! hash_equals($expected, mb_strtolower($actual))) {
            File::delete($path);

            throw new RuntimeException(
                'Refusing to restore: the downloaded archive does not match the checksum the server recorded '
                .'for it. The download was corrupted or tampered with in transit.'
            );
        }
    }

    /**
     * Reject a storage entry that would be dangerous under a web-served directory:
     * an executable/interpretable file (a webshell), an override file, or a path
     * that still tries to traverse out.
     *
     * @throws RuntimeException When the entry is unsafe to write.
     */
    private function assertSafeStorageEntry(string $relativePath): void
    {
        $normalized = str_replace('\\', '/', $relativePath);

        if (str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
            throw new RuntimeException('Refusing to restore: storage entry escapes the target directory ("'.$relativePath.'").');
        }

        $basename = mb_strtolower(basename($normalized));

        $forbiddenNames = ['.htaccess', '.htpasswd', '.user.ini', 'web.config'];

        if (in_array($basename, $forbiddenNames, true)) {
            throw new RuntimeException('Refusing to restore: backup contains a server-override file ("'.$relativePath.'").');
        }

        $extension = mb_strtolower(pathinfo($basename, PATHINFO_EXTENSION));

        $executable = [
            'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phps', 'pht', 'phtm', 'phtml', 'phar',
            'cgi', 'pl', 'py', 'sh', 'bash', 'asp', 'aspx', 'jsp', 'jspx', 'exe', 'so', 'dll', 'htaccess',
        ];

        if (in_array($extension, $executable, true)) {
            throw new RuntimeException(
                'Refusing to restore: backup contains an executable/interpretable file ("'.$relativePath.'"). '
                .'A storage restore must not be able to drop code into a web-served directory.'
            );
        }
    }
}
