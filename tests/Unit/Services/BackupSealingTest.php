<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Enums\SealStatusEnum;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\NotifierStorageService;
use Devuni\Notifier\Services\Zip\BackupSeal;
use Devuni\Notifier\Services\Zip\PhpZipCreator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Sealing as part of taking a backup: each service seals with its own type,
| and what gets uploaded is the sealed file.
|--------------------------------------------------------------------------
*/

const BACKUP_SEALING_PASSWORD = 'a-long-enough-test-password';

function sealingDatabaseService(): NotifierDatabaseService
{
    $dumper = new class implements DatabaseDumperInterface
    {
        public static function isAvailable(): bool
        {
            return true;
        }

        public function dump(string $outputPath): void
        {
            file_put_contents($outputPath, 'CREATE TABLE t (id int);');
        }

        public function describe(): string
        {
            return 'fake-dumper';
        }
    };

    return new NotifierDatabaseService(app(ChunkedUploadService::class), app(PhpZipCreator::class), $dumper, new NotifierLoggerService);
}

function sealingStorageService(): NotifierStorageService
{
    return new NotifierStorageService(app(ChunkedUploadService::class), app(PhpZipCreator::class), new NotifierLoggerService);
}

beforeEach(function () {
    config()->set('notifier.backup_url', 'https://notifier.test/api/v1/repositories/7');
    config()->set('notifier.backup_code', 'the-upload-code');
    config()->set('notifier.backup_zip_password', BACKUP_SEALING_PASSWORD);
    config()->set('notifier.min_storage_backup_bytes', 1);

    $this->inspect = fn (string $path, BackupTypeEnum $type) => (new BackupSeal)
        ->inspect($path, $type, BACKUP_SEALING_PASSWORD, config('notifier.backup_url'))
        ->status;

    $this->storageFile = storage_path('app/public/sealing-test-'.uniqid().'.txt');
    File::ensureDirectoryExists(dirname($this->storageFile));
    File::put($this->storageFile, 'storage contents');
});

afterEach(function () {
    File::delete($this->storageFile);
});

it('seals a database backup as a database backup', function () {
    $path = sealingDatabaseService()->createDatabaseBackup();

    expect(($this->inspect)($path, BackupTypeEnum::Database))->toBe(SealStatusEnum::Ok)
        ->and(($this->inspect)($path, BackupTypeEnum::Storage))->toBe(SealStatusEnum::WrongType)
        ->and(Cache::get(HeartbeatService::LAST_BACKUP_SEALED_KEY))->toBeTrue();

    File::delete($path);
});

it('seals a storage backup as a storage backup', function () {
    $path = sealingStorageService()->createStorageBackup();

    expect(($this->inspect)($path, BackupTypeEnum::Storage))->toBe(SealStatusEnum::Ok)
        ->and(($this->inspect)($path, BackupTypeEnum::Database))->toBe(SealStatusEnum::WrongType);

    File::delete($path);
});

it('leaves backups unsealed when sealing is switched off', function () {
    config()->set('notifier.seal_backups', false);

    $path = sealingDatabaseService()->createDatabaseBackup();

    expect(($this->inspect)($path, BackupTypeEnum::Database))->toBe(SealStatusEnum::Unsealed)
        ->and(Cache::get(HeartbeatService::LAST_BACKUP_SEALED_KEY))->toBeFalse();

    File::delete($path);
});

it('uploads the sealed file, with its size and checksum', function () {
    $path = sealingDatabaseService()->createDatabaseBackup();
    $size = File::size($path);
    $checksum = hash_file('sha256', $path);

    expect(($this->inspect)($path, BackupTypeEnum::Database))->toBe(SealStatusEnum::Ok);

    Http::fake(['*' => Http::response(['status' => 'completed', 'upload_id' => 'u1', 'chunk_size' => 1048576, 'chunks_received' => 1], 200)]);

    try {
        app(ChunkedUploadService::class)->upload($path, BackupTypeEnum::Database->value);
    } catch (Throwable) {
        // Only the first request matters here, however the fake answers the rest.
    }

    File::delete($path);

    $init = Http::recorded()->first()[0]->data();

    expect($init['total_size'])->toBe($size)
        ->and($init['checksum'])->toBe($checksum);
});
