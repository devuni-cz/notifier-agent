<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Services\VerifiedArchive;
use Devuni\Notifier\Services\Zip\BackupSeal;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| The restore policy in one table: what may be restored, what needs a person
| to approve one specific file, and what is never allowed.
|--------------------------------------------------------------------------
*/

const VERIFIED_ARCHIVE_URL = 'https://notifier.test/api/v1/repositories/7';
const VERIFIED_ARCHIVE_PASSWORD = 'a-long-enough-test-password';

/**
 * @param  array{type?: BackupTypeEnum, id?: bool, digest?: string|null, password?: string, url?: string, now?: CarbonImmutable}  $options
 */
function establishArchive(string $path, array $options = []): VerifiedArchive
{
    return VerifiedArchive::establish(
        path: $path,
        type: $options['type'] ?? BackupTypeEnum::Database,
        backupId: 42,
        explicitId: $options['id'] ?? false,
        acceptedDigest: $options['digest'] ?? null,
        password: $options['password'] ?? VERIFIED_ARCHIVE_PASSWORD,
        backupUrl: $options['url'] ?? VERIFIED_ARCHIVE_URL,
        seal: new BackupSeal,
        now: $options['now'] ?? CarbonImmutable::now(),
    );
}

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/verified-'.uniqid();
    File::ensureDirectoryExists($this->work);
    $this->path = $this->work.'/archive.zip';

    makeEncryptedArchive($this->path, VERIFIED_ARCHIVE_PASSWORD);
    $this->created = hash_file('sha256', $this->path);

    $this->seal = fn (BackupTypeEnum $type = BackupTypeEnum::Database) => (new BackupSeal)
        ->seal($this->path, $type, VERIFIED_ARCHIVE_PASSWORD, VERIFIED_ARCHIVE_URL);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

describe('a sealed archive', function () {
    it('is accepted and handed on as the creator wrote it', function (bool $explicitId) {
        ($this->seal)();

        $archive = establishArchive($this->path, ['id' => $explicitId]);

        expect($archive->sealed)->toBeTrue()
            ->and($archive->isZip)->toBeTrue()
            ->and($archive->foreignSite)->toBeFalse()
            ->and($archive->sealedAt)->not->toBeNull()
            ->and($archive->describe())->toContain('Authenticated database backup')
            ->and(hash_file('sha256', $this->path))->toBe($this->created);
    })->with(['newest' => false, 'chosen by id' => true]);

    it('is accepted as the newest backup at exactly the age limit', function () {
        ($this->seal)();
        $sealedAt = (new BackupSeal)->inspect($this->path, BackupTypeEnum::Database, VERIFIED_ARCHIVE_PASSWORD, VERIFIED_ARCHIVE_URL)->sealedAt;

        $archive = establishArchive($this->path, ['now' => CarbonImmutable::createFromTimestampUTC($sealedAt)->addDays(35)]);

        expect($archive->sealed)->toBeTrue();
    });

    it('is refused as the newest backup one second past the age limit', function () {
        ($this->seal)();
        $sealedAt = (new BackupSeal)->inspect($this->path, BackupTypeEnum::Database, VERIFIED_ARCHIVE_PASSWORD, VERIFIED_ARCHIVE_URL)->sealedAt;

        expect(fn () => establishArchive($this->path, ['now' => CarbonImmutable::createFromTimestampUTC($sealedAt)->addDays(35)->addSecond()]))
            ->toThrow(RuntimeException::class, 're-run with --id=42');
    });

    it('can be chosen by id at any age', function () {
        ($this->seal)();

        $archive = establishArchive($this->path, ['id' => true, 'now' => CarbonImmutable::now()->addYears(3)]);

        expect($archive->sealed)->toBeTrue();
    });

    it('is never accepted as the other backup type, even when approved', function () {
        ($this->seal)();
        $digest = hash_file('sha256', $this->path);

        expect(fn () => establishArchive($this->path, ['type' => BackupTypeEnum::Storage, 'id' => true, 'digest' => $digest]))
            ->toThrow(RuntimeException::class, 'different type');
    });

    it('from another repository needs approval and stays marked as foreign', function () {
        ($this->seal)();
        $digest = hash_file('sha256', $this->path);
        $other = ['url' => 'https://notifier.test/api/v1/repositories/8'];

        expect(fn () => establishArchive($this->path, $other))
            ->toThrow(RuntimeException::class, '--accept-unverified='.$digest);

        $archive = establishArchive($this->path, [...$other, 'id' => true, 'digest' => $digest]);

        expect($archive->sealed)->toBeTrue()
            ->and($archive->foreignSite)->toBeTrue();
    });
});

describe('an archive that cannot be authenticated', function () {
    dataset('unauthenticated archives', [
        'no seal' => [fn () => null],
        'altered after sealing' => [function () {
            ($this->seal)();
            $bytes = File::get($this->path);
            $bytes[40] = chr(ord($bytes[40]) ^ 1);
            File::put($this->path, $bytes);
        }],
        'sealed with another password' => [function () {
            (new BackupSeal)->seal($this->path, BackupTypeEnum::Database, 'some-other-password', VERIFIED_ARCHIVE_URL);
        }],
    ]);

    it('is refused, naming the digest to approve', function (Closure $arrange) {
        $arrange->call($this);
        $digest = hash_file('sha256', $this->path);

        expect(fn () => establishArchive($this->path, ['id' => true]))
            ->toThrow(RuntimeException::class, '--id=42 --accept-unverified='.$digest);
    })->with('unauthenticated archives');

    it('is refused when the approval names a different file', function (Closure $arrange) {
        $arrange->call($this);

        expect(fn () => establishArchive($this->path, ['id' => true, 'digest' => str_repeat('a', 64)]))
            ->toThrow(RuntimeException::class, 'Refusing to restore');
    })->with('unauthenticated archives');

    it('is accepted as unverified when that exact file is approved', function (Closure $arrange) {
        $arrange->call($this);
        $digest = hash_file('sha256', $this->path);

        $archive = establishArchive($this->path, ['id' => true, 'digest' => mb_strtoupper($digest)]);

        expect($archive->sealed)->toBeFalse()
            ->and($archive->isZip)->toBeTrue()
            ->and($archive->describe())->toContain('UNVERIFIED')
            ->and(hash_file('sha256', $this->path))->toBe($digest);
    })->with('unauthenticated archives');

    it('needs --id alongside the approval', function () {
        $digest = hash_file('sha256', $this->path);

        expect(fn () => establishArchive($this->path, ['digest' => $digest]))
            ->toThrow(RuntimeException::class, 'together with --id');
    });
});

describe('a host with no backup password', function () {
    it('needs approval even for a ZIP', function () {
        $digest = hash_file('sha256', $this->path);

        expect(fn () => establishArchive($this->path, ['password' => '', 'id' => true]))
            ->toThrow(RuntimeException::class, '--accept-unverified='.$digest);

        expect(establishArchive($this->path, ['password' => '', 'id' => true, 'digest' => $digest])->sealed)->toBeFalse();
    });

    it('can approve a bare database dump', function () {
        File::put($this->path, str_repeat("-- dump\n", 20));
        $digest = hash_file('sha256', $this->path);

        $archive = establishArchive($this->path, ['password' => '', 'id' => true, 'digest' => $digest]);

        expect($archive->isZip)->toBeFalse();
    });

    it('never accepts a bare file as a storage backup', function () {
        File::put($this->path, str_repeat("-- dump\n", 20));
        $digest = hash_file('sha256', $this->path);

        expect(fn () => establishArchive($this->path, ['type' => BackupTypeEnum::Storage, 'password' => '', 'id' => true, 'digest' => $digest]))
            ->toThrow(RuntimeException::class, 'must be a ZIP');
    });
});

it('never accepts a non-ZIP when a password is configured, even when approved', function () {
    File::put($this->path, str_repeat("-- dump\n", 20));
    $digest = hash_file('sha256', $this->path);

    expect(fn () => establishArchive($this->path, ['id' => true, 'digest' => $digest]))
        ->toThrow(RuntimeException::class, 'not a ZIP archive');
});
