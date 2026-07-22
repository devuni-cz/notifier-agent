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
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'size' => isset($row['size']) && is_numeric($row['size']) ? (int) $row['size'] : null,
                'checksum' => $this->normalizeChecksum($row['checksum'] ?? null),
                'created_at' => is_string($row['created_at'] ?? null) ? $row['created_at'] : null,
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
     * When $expectedChecksum is a SHA-256 the server vouched for, the downloaded
     * bytes are hashed and compared before the file is accepted. This is the
     * archive's authenticity check against a tampered / substituted download: a
     * hostile or hijacked control plane cannot feed the importer a dump whose
     * hash the server itself never recorded. A null checksum means the server
     * had none to give (pre-checksum backup) - the download proceeds, but the
     * encryption + SQL-directive guards downstream remain the last line.
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
     * Extract a downloaded archive into a working directory.
     *
     * A database backup taken on a password-less install is a BARE .sql, not a
     * ZIP (storage backups are always zipped). Detect a non-ZIP download by its
     * magic bytes and handle it directly rather than feeding it to the zip
     * extractor (which would throw "unable to open archive"). When a password IS
     * configured every backup must be an encrypted ZIP, so a non-ZIP download can
     * only be a corrupted/substituted archive and is refused - the encrypted-ZIP
     * authenticity guarantee must not be silently downgraded to "import raw
     * server bytes".
     *
     * @return string Path to the extraction directory.
     */
    public function extract(string $archivePath, string $workingDirectory): string
    {
        $password = (string) config('notifier.backup_zip_password');

        File::ensureDirectoryExists($workingDirectory);

        if (! $this->isZipArchive($archivePath)) {
            if ($password !== '') {
                throw new RuntimeException(
                    'Refusing to restore: a backup password is configured, so every backup must be an '
                    .'encrypted ZIP, but the downloaded file is not a ZIP archive. It was corrupted or substituted.'
                );
            }

            // No password: the only non-ZIP this package produces is a bare .sql
            // database dump. Stage it so importDatabase()'s glob('*.sql') finds
            // exactly one dump; SqlDirectiveGuard still runs inside the importer.
            File::copy($archivePath, $workingDirectory.'/backup.sql');

            return $workingDirectory;
        }

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

        // $hidden = true is load-bearing: allFiles() skips dotfiles by default, which
        // would silently drop legitimate dotfiles from the backup AND make the
        // .htaccess/.htpasswd/.user.ini guard below unreachable dead code. Every
        // entry the archive carries must be seen so it is either validated+restored
        // or rejected.
        $files = File::allFiles($extractedDirectory, true);

        // The archive is server-controlled: it is written into storage/app/public,
        // which the web server serves. Validate EVERY entry before writing anything,
        // so a single .php/.htaccess in the archive aborts the restore instead of
        // dropping a webshell into a live, web-reachable directory.
        foreach ($files as $file) {
            $this->assertSafeStorageEntry($file->getRelativePathname());
        }

        $written = 0;

        foreach ($files as $file) {
            $relative = $file->getRelativePathname();
            $target = mb_rtrim($storagePath, '/').'/'.$relative;

            File::ensureDirectoryExists(dirname($target));
            File::copy($file->getPathname(), $target);

            $written++;
        }

        return $written;
    }

    /**
     * Peek at the first bytes to decide whether a download is a ZIP archive.
     * A pg_dump/mysqldump plaintext dump never begins with the "PK" signature.
     */
    private function isZipArchive(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        return in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
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
     * Verify the downloaded archive against the SHA-256 the server vouched for.
     *
     * The expected hash is taken from the backup listing ($expected); the
     * download response also carries it in a header ($headerChecksum) as a
     * second copy. If both are present they must agree, and the file must hash
     * to that value. A wrong hash deletes the file and aborts - the importer
     * never sees an archive whose provenance the server does not confirm.
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

        $extension = mb_strtolower((string) pathinfo($basename, PATHINFO_EXTENSION));

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
