<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Enums\SealStatusEnum;
use Devuni\Notifier\Services\Zip\BackupSeal;
use RuntimeException;

/**
 * A downloaded backup that has passed the restore policy. establish() is the only
 * way to get one and NotifierRestoreService::extract() requires one, so no caller
 * can reach an extractor without going through the policy.
 *
 * Everything the control plane sends is untrusted here, including the checksum it
 * reports and the dates it lists. Only the seal, keyed from the backup password,
 * says where an archive came from.
 */
final readonly class VerifiedArchive
{
    /**
     * How old the seal of a backup restored without --id may be. Long enough for
     * the slowest (monthly) backup schedule plus a few days of slack.
     */
    public const int NEWEST_BACKUP_MAX_AGE_DAYS = 35;

    private function __construct(
        public string $path,
        public BackupTypeEnum $type,
        public bool $sealed,
        public bool $isZip,
        public ?CarbonImmutable $sealedAt = null,
        public bool $foreignSite = false,
    ) {}

    /**
     * Apply the restore policy to a downloaded file.
     *
     * @param  string|null  $acceptedDigest  The SHA-256 the operator approved with --accept-unverified.
     *
     * @throws RuntimeException When the file may not be restored.
     */
    public static function establish(
        string $path,
        BackupTypeEnum $type,
        int $backupId,
        bool $explicitId,
        ?string $acceptedDigest,
        string $password,
        string $backupUrl,
        BackupSeal $seal,
        CarbonInterface $now,
    ): self {
        if ($acceptedDigest !== null && ! $explicitId) {
            throw new RuntimeException('--accept-unverified must be used together with --id, so the approval names one specific backup.');
        }

        $isZip = self::isZipArchive($path);

        if ($password === '') {
            return self::withoutPassword($path, $type, $backupId, $acceptedDigest, $isZip);
        }

        if (! $isZip) {
            throw new RuntimeException(
                'Refusing to restore: a backup password is configured, so every backup must be an '
                .'encrypted ZIP, but the downloaded file is not a ZIP archive. It was corrupted or substituted.'
            );
        }

        $inspection = $seal->inspect($path, $type, $password, $backupUrl);
        $requireApproval = fn (string $why) => self::requireApproval($path, $backupId, $acceptedDigest, $why);

        match ($inspection->status) {
            SealStatusEnum::Ok => null,
            SealStatusEnum::WrongType => throw new RuntimeException('Refusing to restore: this archive is a sealed backup of a different type than the one requested.'),
            SealStatusEnum::WrongSite => $requireApproval('this archive was sealed for a different repository than NOTIFIER_URL points to.'),
            SealStatusEnum::Unsealed => $requireApproval('this archive has no seal, so its origin cannot be confirmed. Backups taken before sealing was introduced look like this, and so does one whose seal was removed.'),
            SealStatusEnum::Tampered => $requireApproval('this archive does not match its seal. It is corrupt or was altered after it was created.'),
            SealStatusEnum::WrongPassword => $requireApproval('this archive\'s seal does not match NOTIFIER_BACKUP_PASSWORD. The password is wrong or the seal was altered.'),
        };

        // Only a seal that authenticated reports what it covers.
        if ($inspection->coveredLength === null) {
            return new self($path, $type, false, true);
        }

        $sealedAt = CarbonImmutable::createFromTimestampUTC((int) $inspection->sealedAt);
        self::assertRecentEnough($sealedAt, $backupId, $explicitId, $now);

        // The digest above was taken from the file as downloaded, so this comes after it.
        $seal->strip($path, $inspection->coveredLength);

        return new self($path, $type, true, true, $sealedAt, $inspection->status === SealStatusEnum::WrongSite);
    }

    public function describe(): string
    {
        if (! $this->sealed || ! $this->sealedAt instanceof CarbonImmutable) {
            return 'UNVERIFIED: this archive could not be authenticated. It is being restored only because you approved this exact file.';
        }

        $kind = $this->type === BackupTypeEnum::Database ? 'database' : 'storage';

        return 'Authenticated '.$kind.' backup, sealed '.$this->sealedAt->toDateTimeString().' UTC ('.$this->sealedAt->diffForHumans().').';
    }

    private static function withoutPassword(string $path, BackupTypeEnum $type, int $backupId, ?string $acceptedDigest, bool $isZip): self
    {
        // Only a database backup can be a bare file; a storage backup is always a ZIP.
        if (! $isZip && $type === BackupTypeEnum::Storage) {
            throw new RuntimeException('Refusing to restore: a storage backup must be a ZIP archive, but the downloaded file is not one.');
        }

        self::requireApproval($path, $backupId, $acceptedDigest, 'no backup password is configured, so this archive cannot be authenticated.');

        return new self($path, $type, false, $isZip);
    }

    /**
     * @throws RuntimeException Naming the digest to approve, unless it was approved.
     */
    private static function requireApproval(string $path, int $backupId, ?string $acceptedDigest, string $why): void
    {
        $digest = (string) hash_file('sha256', $path);

        if ($acceptedDigest !== null && hash_equals($digest, mb_strtolower($acceptedDigest))) {
            return;
        }

        throw new RuntimeException(
            'Refusing to restore: '.$why.' If you trust this exact file, re-run with --id='.$backupId.' --accept-unverified='.$digest
        );
    }

    private static function assertRecentEnough(CarbonImmutable $sealedAt, int $backupId, bool $explicitId, CarbonInterface $now): void
    {
        if ($explicitId || $sealedAt->greaterThanOrEqualTo($now->copy()->subDays(self::NEWEST_BACKUP_MAX_AGE_DAYS))) {
            return;
        }

        throw new RuntimeException(
            'Refusing to restore: the newest backup the server offered was sealed on '.$sealedAt->toDateTimeString()
            .' UTC, more than '.self::NEWEST_BACKUP_MAX_AGE_DAYS.' days ago. If this is the backup you want, re-run with --id='.$backupId.'.'
        );
    }

    /**
     * Peek at the first bytes to decide whether a download is a ZIP archive.
     * A pg_dump/mysqldump plaintext dump never begins with the "PK" signature.
     */
    private static function isZipArchive(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 4);
        fclose($handle);

        return in_array($magic, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }
}
