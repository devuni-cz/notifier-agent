<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Zip;

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Enums\SealStatusEnum;
use Devuni\Notifier\Services\HeartbeatService;
use Illuminate\Support\Facades\Cache;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Seals a finished backup archive with a key derived from the backup password,
 * and checks that seal before a restore opens the archive.
 *
 * The seal covers every byte the ZIP creator wrote and lives in the archive's
 * own ZIP comment, so the file stays a standard ZIP whichever tool made it and
 * the control plane needs no changes. Checking it reads raw bytes at fixed
 * offsets; no ZIP reader sees the file until the seal has passed.
 *
 * The layout is frozen once sealed backups exist: changing a constant or a key
 * label here would orphan them. tests/Fixtures/seal pins it.
 */
final readonly class BackupSeal
{
    /**
     * Exception code for a seal that could not be rolled back, which leaves the
     * file in an unknown state. Every other failure leaves it as it was.
     */
    public const int UNDO_FAILED = 2;

    private const string PREFIX = 'NOTIFIER-SEAL/1:';

    private const string CONTEXT = 'notifier-seal/1';

    private const int COMMENT_LENGTH = 258;

    private const int EOCD_LENGTH = 22;

    private const int TAIL_LENGTH = self::EOCD_LENGTH + self::COMMENT_LENGTH;

    private const int BODY_LENGTH = 89;

    private const string BODY_FORMAT = 'a16salt/a8kcv/Ctype/JsealedAt/JcoveredLength/a16site/a32digest';

    private const int PBKDF2_ITERATIONS = 600_000;

    /**
     * Identify the site a backup belongs to from its NOTIFIER_URL: the repository
     * id when the URL has one, so a change of host keeps old backups valid.
     */
    public static function siteId(string $backupUrl): string
    {
        $path = (string) parse_url($backupUrl, PHP_URL_PATH);

        $canonical = preg_match('#/repositories/([^/]+)#', $path, $match) === 1
            ? 'repository:'.$match[1]
            : 'url:'.mb_strtolower((string) parse_url($backupUrl, PHP_URL_HOST)).mb_rtrim($path, '/');

        return mb_substr(hash('sha256', self::CONTEXT." site\0".$canonical, true), 0, 16, '8bit');
    }

    /**
     * Seal a finished archive in place.
     *
     * @throws RuntimeException When the archive cannot be sealed. Code UNDO_FAILED
     *                          means the file could not be put back as it was.
     */
    public function seal(string $path, BackupTypeEnum $type, string $password, string $backupUrl): void
    {
        [$size, $comment] = $this->prepareComment($path, $type, $password, $backupUrl);

        if (! $this->appendComment($path, $size, $comment) || ! $this->isIntact($path, $size, $comment)) {
            $this->undo($path, $size);

            throw new RuntimeException('Could not seal the archive; it was left unsealed.');
        }
    }

    /**
     * Seal an archive without letting a sealing problem cost the backup: an
     * unsealed backup can still be restored by hand, a missing one cannot.
     * The outcome is logged and reported through the heartbeat.
     */
    public function sealQuietly(string $path, BackupTypeEnum $type, string $password, LoggerInterface $logger): void
    {
        $sealed = false;

        if (config('notifier.seal_backups', true)) {
            try {
                $this->seal($path, $type, $password, (string) config('notifier.backup_url'));
                $sealed = true;
                $logger->info('➡️ backup archive sealed');
            } catch (Throwable $e) {
                $logger->error('❌ could not seal the backup archive, it will need manual approval to restore', [
                    'error' => $e->getMessage(),
                ]);

                if ($e->getCode() === self::UNDO_FAILED) {
                    $this->recordOutcome(false, $logger);

                    throw $e;
                }
            }
        }

        $this->recordOutcome($sealed, $logger);
    }

    /**
     * Check an archive's seal. Reads raw bytes only.
     *
     * @throws RuntimeException When the file cannot be read.
     */
    public function inspect(string $path, BackupTypeEnum $type, string $password, string $backupUrl): SealInspection
    {
        clearstatcache(true, $path);
        $size = @filesize($path);

        if ($size === false || $size < self::TAIL_LENGTH) {
            return new SealInspection(SealStatusEnum::Unsealed);
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Could not read the archive to check its seal.');
        }

        try {
            fseek($handle, $size - self::TAIL_LENGTH);
            $tail = (string) fread($handle, self::TAIL_LENGTH);
            $raw = $this->parseTail($tail);

            if ($raw === null) {
                return new SealInspection(str_contains($tail, self::PREFIX) ? SealStatusEnum::Tampered : SealStatusEnum::Unsealed);
            }

            return $this->authenticate($handle, $size, $raw, $type, $password, $backupUrl);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Remove a verified seal from a private copy, so ZIP readers get exactly the
     * bytes the creator wrote.
     *
     * @throws RuntimeException When the file cannot be rewritten.
     */
    public function strip(string $path, int $coveredLength): void
    {
        $handle = @fopen($path, 'r+b');

        $stripped = $handle !== false
            && ftruncate($handle, $coveredLength + 2)
            && fseek($handle, $coveredLength) === 0
            && fwrite($handle, "\0\0") === 2;

        if ($handle !== false) {
            fclose($handle);
        }

        clearstatcache(true, $path);

        if (! $stripped) {
            throw new RuntimeException('Could not prepare the verified archive for extraction.');
        }
    }

    /**
     * Validate the archive and build its seal without changing the file.
     *
     * @return array{0: int, 1: string} The archive size and the comment to write.
     */
    private function prepareComment(string $path, BackupTypeEnum $type, string $password, string $backupUrl): array
    {
        clearstatcache(true, $path);
        $size = @filesize($path);
        $handle = @fopen($path, 'rb');

        if ($size === false || $size < self::EOCD_LENGTH || $handle === false) {
            throw new RuntimeException('Cannot seal "'.$path.'": the file is missing, unreadable or too small.');
        }

        try {
            fseek($handle, $size - self::EOCD_LENGTH);

            // A finished archive with no comment of its own ends in these 22 bytes.
            if (! $this->isCommentlessEocd((string) fread($handle, self::EOCD_LENGTH))) {
                throw new RuntimeException('Cannot seal "'.$path.'": it does not end like a finished ZIP without a comment.');
            }

            // Hashing a large archive can outlast the time left in a web request.
            // Hosts may disable this function, which makes calling it an Error.
            if (function_exists('set_time_limit')) {
                @set_time_limit((int) ini_get('max_execution_time'));
            }

            rewind($handle);
            $coveredLength = $size - 2;
            $context = hash_init('sha256');

            if (hash_update_stream($context, $handle, $coveredLength) !== $coveredLength) {
                throw new RuntimeException('Cannot seal "'.$path.'": the file could not be read in full.');
            }

            return [$size, $this->buildComment(hash_final($context, true), $coveredLength, $type, $password, $backupUrl)];
        } finally {
            fclose($handle);
        }
    }

    private function buildComment(string $digest, int $coveredLength, BackupTypeEnum $type, string $password, string $backupUrl): string
    {
        $salt = random_bytes(16);
        $keys = $this->keys($password, $salt);

        $body = pack('a16a8CJJa16a32', $salt, $keys['kcv'], $this->typeCode($type), time(), $coveredLength, self::siteId($backupUrl), $digest);

        return self::PREFIX.bin2hex($body.$this->tag($body, $keys['mac']));
    }

    /**
     * Write the comment bytes first and the length second: until the length
     * lands, the file is still the creator's valid, comment-less ZIP.
     */
    private function appendComment(string $path, int $size, string $comment): bool
    {
        $handle = @fopen($path, 'r+b');

        if ($handle === false) {
            return false;
        }

        try {
            return fseek($handle, $size) === 0
                && fwrite($handle, $comment) === self::COMMENT_LENGTH
                && fflush($handle)
                && fseek($handle, $size - 2) === 0
                && fwrite($handle, pack('v', self::COMMENT_LENGTH)) === 2
                && fflush($handle);
        } finally {
            fclose($handle);
            clearstatcache(true, $path);
        }
    }

    private function isIntact(string $path, int $size, string $comment): bool
    {
        if (@filesize($path) !== $size + self::COMMENT_LENGTH) {
            return false;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        fseek($handle, $size);
        $written = fread($handle, self::COMMENT_LENGTH);
        fclose($handle);

        if ($written !== $comment) {
            return false;
        }

        if (! class_exists(ZipArchive::class)) {
            return true;
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $zip->close();

        return true;
    }

    /**
     * @throws RuntimeException With code UNDO_FAILED when the file cannot be restored.
     */
    private function undo(string $path, int $size): void
    {
        $handle = @fopen($path, 'r+b');

        $restored = $handle !== false
            && ftruncate($handle, $size)
            && fseek($handle, $size - 2) === 0
            && fwrite($handle, "\0\0") === 2;

        if ($handle !== false) {
            fclose($handle);
        }

        clearstatcache(true, $path);

        if (! $restored) {
            throw new RuntimeException('Could not seal the archive, and could not put it back as it was.', self::UNDO_FAILED);
        }
    }

    private function isCommentlessEocd(string $bytes): bool
    {
        if (mb_strlen($bytes, '8bit') !== self::EOCD_LENGTH) {
            return false;
        }

        $eocd = unpack('a4signature/x16/vcommentLength', $bytes);

        return is_array($eocd) && $eocd['signature'] === "PK\x05\x06" && $eocd['commentLength'] === 0;
    }

    /**
     * @return string|null The decoded seal, or null when the tail is not a well-formed one.
     */
    private function parseTail(string $tail): ?string
    {
        if (mb_strlen($tail, '8bit') !== self::TAIL_LENGTH) {
            return null;
        }

        $fields = unpack('a4signature/x16/vcommentLength/a*comment', $tail);

        if (! is_array($fields) || $fields['signature'] !== "PK\x05\x06" || $fields['commentLength'] !== self::COMMENT_LENGTH) {
            return null;
        }

        $pattern = '/\A'.preg_quote(self::PREFIX, '/').'([0-9a-f]{242})\z/D';

        if (preg_match($pattern, $fields['comment'], $match) !== 1) {
            return null;
        }

        return (string) hex2bin($match[1]);
    }

    /**
     * @param  resource  $handle
     */
    private function authenticate($handle, int $size, string $raw, BackupTypeEnum $type, string $password, string $backupUrl): SealInspection
    {
        $body = mb_substr($raw, 0, self::BODY_LENGTH, '8bit');

        /** @var array{salt: string, kcv: string, type: int, sealedAt: int, coveredLength: int, site: string, digest: string} $fields */
        $fields = unpack(self::BODY_FORMAT, $body);
        $keys = $this->keys($password, $fields['salt']);

        if (! hash_equals($keys['kcv'], $fields['kcv'])) {
            return new SealInspection(SealStatusEnum::WrongPassword);
        }

        if (! hash_equals($this->tag($body, $keys['mac']), mb_substr($raw, self::BODY_LENGTH, null, '8bit'))) {
            return new SealInspection(SealStatusEnum::Tampered);
        }

        // Every field in the body is authenticated from here on.
        if ($size !== $fields['coveredLength'] + 2 + self::COMMENT_LENGTH) {
            return new SealInspection(SealStatusEnum::Tampered);
        }

        if ($fields['type'] !== $this->typeCode($type)) {
            return new SealInspection(SealStatusEnum::WrongType);
        }

        rewind($handle);
        $context = hash_init('sha256');
        $read = hash_update_stream($context, $handle, $fields['coveredLength']);

        if ($read !== $fields['coveredLength'] || ! hash_equals($fields['digest'], hash_final($context, true))) {
            return new SealInspection(SealStatusEnum::Tampered);
        }

        $status = hash_equals(self::siteId($backupUrl), $fields['site']) ? SealStatusEnum::Ok : SealStatusEnum::WrongSite;

        return new SealInspection($status, $fields['sealedAt'], $fields['coveredLength']);
    }

    /**
     * @return array{mac: string, kcv: string}
     */
    private function keys(string $password, string $salt): array
    {
        $root = hash_pbkdf2('sha256', $password, self::CONTEXT."\0".$salt, self::PBKDF2_ITERATIONS, 32, true);

        return [
            'mac' => hash_hkdf('sha256', $root, 32, self::CONTEXT.' mac'),
            'kcv' => hash_hkdf('sha256', $root, 8, self::CONTEXT.' kcv'),
        ];
    }

    private function tag(string $body, string $macKey): string
    {
        return hash_hmac('sha256', 'NOTIFIER-SEAL/1'."\0".$body, $macKey, true);
    }

    private function typeCode(BackupTypeEnum $type): int
    {
        return match ($type) {
            BackupTypeEnum::Database => 1,
            BackupTypeEnum::Storage => 2,
        };
    }

    private function recordOutcome(bool $sealed, LoggerInterface $logger): void
    {
        // Best-effort: a cache-store hiccup must not fail a backup that succeeded.
        try {
            Cache::forever(HeartbeatService::LAST_BACKUP_SEALED_KEY, $sealed);
        } catch (Throwable $e) {
            $logger->warning('⚠️ could not record the seal outcome for the heartbeat', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
