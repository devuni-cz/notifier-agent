<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Enums\SealStatusEnum;
use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\Zip\BackupSeal;
use Devuni\Notifier\Services\Zip\SealInspection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Psr\Log\AbstractLogger;

/*
|--------------------------------------------------------------------------
| The seal is what lets a restore tell a backup this site made from anything
| else. These pin the on-disk format (a change must fail a test instead of
| orphaning stored backups), prove every byte of the file is covered, and that
| a sealing problem never damages the backup itself.
|--------------------------------------------------------------------------
*/

const BACKUP_SEAL_URL = 'https://notifier.test/api/v1/repositories/52740614';
const BACKUP_SEAL_PASSWORD = 'notifier-golden-password-01';

function inspectSealed(string $path, BackupTypeEnum $type = BackupTypeEnum::Database, string $password = BACKUP_SEAL_PASSWORD, string $url = BACKUP_SEAL_URL): SealInspection
{
    return (new BackupSeal)->inspect($path, $type, $password, $url);
}

function sealLogSpy(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $errors = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            if ($level === 'error') {
                $this->errors[] = (string) $message;
            }
        }
    };
}

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/seal-'.uniqid();
    File::ensureDirectoryExists($this->work);
    $this->path = $this->work.'/backup.zip';
    $this->seal = new BackupSeal;
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('still verifies backups sealed in the published format', function (BackupTypeEnum $type, string $fixture, string $entry, string $contents) {
    File::copy(dirname(__DIR__, 3).'/Fixtures/seal/'.$fixture, $this->path);

    $inspection = inspectSealed($this->path, $type);

    expect($inspection->status)->toBe(SealStatusEnum::Ok)
        ->and($inspection->sealedAt)->toBe(1767225600);

    $this->seal->strip($this->path, $inspection->coveredLength);

    $zip = new ZipArchive;
    $zip->open($this->path);
    $zip->setPassword(BACKUP_SEAL_PASSWORD);

    expect($zip->getFromName($entry))->toBe($contents);

    $zip->close();
})->with([
    'database' => [BackupTypeEnum::Database, 'database.zip', 'dump.sql', "CREATE TABLE golden (id int);\n"],
    'storage' => [BackupTypeEnum::Storage, 'storage.zip', 'uploads/golden.txt', "golden storage file\n"],
]);

it('round-trips and keeps the archive a readable ZIP', function (BackupTypeEnum $type) {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
    $before = time();
    $originalSize = File::size($this->path);

    $this->seal->seal($this->path, $type, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);

    $inspection = inspectSealed($this->path, $type);

    expect($inspection->status)->toBe(SealStatusEnum::Ok)
        ->and($inspection->sealedAt)->toBeGreaterThanOrEqual($before)
        ->and(File::size($this->path))->toBe($originalSize + 258);

    $zip = new ZipArchive;

    expect($zip->open($this->path, ZipArchive::RDONLY))->toBeTrue()
        ->and($zip->getArchiveComment())->toStartWith('NOTIFIER-SEAL/1:');

    $zip->setPassword(BACKUP_SEAL_PASSWORD);

    expect($zip->getFromName('dump.sql'))->toBe('SELECT 1;');

    $zip->close();
})->with([BackupTypeEnum::Database, BackupTypeEnum::Storage]);

it('notices a changed byte anywhere in the file', function (Closure $position, SealStatusEnum $expected) {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD, ['dump.sql' => str_repeat('A long enough body. ', 100)]);
    $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);

    $size = File::size($this->path);
    $bytes = File::get($this->path);
    $offset = $position($size);

    // Seal fields are hex text, so keep them hex: they must fail on content, not shape.
    $bytes[$offset] = $offset >= $size - 242
        ? ($bytes[$offset] === '0' ? '1' : '0')
        : chr(ord($bytes[$offset]) ^ 1);

    File::put($this->path, $bytes);

    expect(inspectSealed($this->path)->status)->toBe($expected);
})->with([
    'first byte' => [fn (int $size): int => 0, SealStatusEnum::Tampered],
    'entry name' => [fn (int $size): int => 30, SealStatusEnum::Tampered],
    'entry data' => [fn (int $size): int => 70, SealStatusEnum::Tampered],
    'central directory' => [fn (int $size): int => $size - 310, SealStatusEnum::Tampered],
    'entry count' => [fn (int $size): int => $size - 272, SealStatusEnum::Tampered],
    'directory offset' => [fn (int $size): int => $size - 264, SealStatusEnum::Tampered],
    'comment length' => [fn (int $size): int => $size - 260, SealStatusEnum::Tampered],
    'seal salt' => [fn (int $size): int => $size - 240, SealStatusEnum::WrongPassword],
    'seal key check' => [fn (int $size): int => $size - 206, SealStatusEnum::WrongPassword],
    'seal type' => [fn (int $size): int => $size - 193, SealStatusEnum::Tampered],
    'seal date' => [fn (int $size): int => $size - 182, SealStatusEnum::Tampered],
    'seal length' => [fn (int $size): int => $size - 172, SealStatusEnum::Tampered],
    'seal site' => [fn (int $size): int => $size - 152, SealStatusEnum::Tampered],
    'seal digest' => [fn (int $size): int => $size - 112, SealStatusEnum::Tampered],
    'seal tag' => [fn (int $size): int => $size - 42, SealStatusEnum::Tampered],
]);

it('notices a file that grew or shrank', function (Closure $change, SealStatusEnum $expected) {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
    $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);

    File::put($this->path, $change(File::get($this->path)));

    expect(inspectSealed($this->path)->status)->toBe($expected);
})->with([
    'a byte appended' => [fn (string $b): string => $b.'x', SealStatusEnum::Tampered],
    'a byte prepended' => [fn (string $b): string => 'x'.$b, SealStatusEnum::Tampered],
    'one byte cut off' => [fn (string $b): string => mb_substr($b, 0, -1, '8bit'), SealStatusEnum::Tampered],
    'the seal cut off' => [fn (string $b): string => mb_substr($b, 0, -258, '8bit'), SealStatusEnum::Unsealed],
    'the tail repeated' => [fn (string $b): string => $b.mb_substr($b, -280, null, '8bit'), SealStatusEnum::Tampered],
]);

it('refuses a seal taken from another archive', function () {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
    $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);

    $other = $this->work.'/other.zip';
    makeEncryptedArchive($other, BACKUP_SEAL_PASSWORD, ['dump.sql' => 'SELECT 2;']);

    File::put($other, mb_substr(File::get($other), 0, -2, '8bit').pack('v', 258).mb_substr(File::get($this->path), -258, null, '8bit'));

    expect(inspectSealed($other)->status)->toBe(SealStatusEnum::Tampered);
});

it('refuses a seal made with another password', function () {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
    $this->seal->seal($this->path, BackupTypeEnum::Database, 'some-other-password', BACKUP_SEAL_URL);

    expect(inspectSealed($this->path)->status)->toBe(SealStatusEnum::WrongPassword);
});

it('reports the type mismatch before reading the whole file', function () {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD, ['dump.sql' => str_repeat('body ', 200)]);
    $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);

    // Damage the covered bytes: a digest check would report this as tampering.
    $bytes = File::get($this->path);
    $bytes[100] = chr(ord($bytes[100]) ^ 1);
    File::put($this->path, $bytes);

    expect(inspectSealed($this->path, BackupTypeEnum::Storage)->status)->toBe(SealStatusEnum::WrongType);
});

it('reports a backup sealed for another repository', function () {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
    $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);

    $inspection = inspectSealed($this->path, url: 'https://notifier.test/api/v1/repositories/99');

    expect($inspection->status)->toBe(SealStatusEnum::WrongSite)
        ->and($inspection->coveredLength)->not->toBeNull();
});

it('treats an ordinary archive or a small file as unsealed', function () {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);

    expect(inspectSealed($this->path)->status)->toBe(SealStatusEnum::Unsealed);

    File::put($this->path, 'tiny');

    expect(inspectSealed($this->path)->status)->toBe(SealStatusEnum::Unsealed);
});

it('identifies a site by its repository, whatever the host', function () {
    expect(BackupSeal::siteId('https://a.example/api/v1/repositories/7'))
        ->toBe(BackupSeal::siteId('https://b.example/api/v1/repositories/7/'))
        ->not->toBe(BackupSeal::siteId('https://a.example/api/v1/repositories/8'));

    expect(BackupSeal::siteId('https://A.example/custom/'))
        ->toBe(BackupSeal::siteId('https://a.example/custom'))
        ->not->toBe(BackupSeal::siteId('https://b.example/custom'));
});

it('restores the bytes the creator wrote when the seal is removed', function () {
    makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
    $original = hash_file('sha256', $this->path);

    $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL);
    $this->seal->strip($this->path, inspectSealed($this->path)->coveredLength);

    expect(hash_file('sha256', $this->path))->toBe($original);
});

it('leaves a file it cannot seal exactly as it was', function (string $contents) {
    File::put($this->path, $contents);

    expect(fn () => $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL))
        ->toThrow(RuntimeException::class);

    expect(File::get($this->path))->toBe($contents);
})->with([
    'not a ZIP' => [str_repeat('x', 100)],
    'too short to be a ZIP' => ['tiny'],
    'already has a comment' => ["PK\x05\x06".str_repeat("\0", 16).pack('v', 3).'abc'],
    'an end record that declares a comment' => ["PK\x05\x06".str_repeat("\0", 16).pack('v', 3)],
    'bytes after the end record' => ["PK\x05\x06".str_repeat("\0", 18).'junk'],
    'a ZIP reader cannot open the result' => [str_repeat('x', 64)."PK\x05\x06".pack('vvvvVVv', 0, 0, 1, 1, 46, 0, 0)],
]);

it('reports a missing file instead of creating one', function () {
    expect(fn () => $this->seal->seal($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, BACKUP_SEAL_URL))
        ->toThrow(RuntimeException::class, 'missing');

    expect(File::exists($this->path))->toBeFalse();
});

describe('sealQuietly', function () {
    beforeEach(function () {
        config()->set('notifier.backup_url', BACKUP_SEAL_URL);
    });

    it('seals the archive and records it for the heartbeat', function () {
        makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);

        $this->seal->sealQuietly($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, sealLogSpy());

        expect(inspectSealed($this->path)->status)->toBe(SealStatusEnum::Ok)
            ->and(Cache::get(HeartbeatService::LAST_BACKUP_SEALED_KEY))->toBeTrue();
    });

    it('does nothing to the file when sealing is switched off, and says so', function () {
        config()->set('notifier.seal_backups', false);
        makeEncryptedArchive($this->path, BACKUP_SEAL_PASSWORD);
        $original = hash_file('sha256', $this->path);

        $this->seal->sealQuietly($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, sealLogSpy());

        expect(hash_file('sha256', $this->path))->toBe($original)
            ->and(Cache::get(HeartbeatService::LAST_BACKUP_SEALED_KEY))->toBeFalse();
    });

    it('keeps the backup and logs an error when the archive cannot be sealed', function () {
        File::put($this->path, str_repeat('x', 100));
        $logger = sealLogSpy();

        $this->seal->sealQuietly($this->path, BackupTypeEnum::Database, BACKUP_SEAL_PASSWORD, $logger);

        expect(File::get($this->path))->toBe(str_repeat('x', 100))
            ->and($logger->errors)->toHaveCount(1)
            ->and(Cache::get(HeartbeatService::LAST_BACKUP_SEALED_KEY))->toBeFalse();
    });
});
