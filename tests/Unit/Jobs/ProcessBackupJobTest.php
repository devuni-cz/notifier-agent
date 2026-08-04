<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Jobs\ProcessBackupJob;
use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\NotifierStorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| ProcessBackupJob contract + behaviour. The queue contract (tries/timeout/
| uniqueness) protects a long-running backup from being retried or timed out
| mid-flight; the behavioural tests run handle() against real services built
| from faked leaf dependencies (the services are `final`, so they cannot be
| Mockery-doubled) with the HTTP layer faked.
|--------------------------------------------------------------------------
*/

/**
 * Bind a database service whose dumper and zip creator are in-memory fakes, so
 * the job's method injection runs the real create -> upload flow without
 * mysqldump, a real Process, or the network.
 */
function bindJobFakeBackupServices(int $storageArchiveBytes = 200_000): void
{
    $dumper = new class implements DatabaseDumperInterface
    {
        public static function isAvailable(): bool
        {
            return true;
        }

        public function dump(string $outputPath): void
        {
            file_put_contents($outputPath, 'SQL DUMP CONTENT');
        }

        public function describe(): string
        {
            return 'fake-dumper 1.0';
        }
    };

    $zip = new class($storageArchiveBytes) implements ZipCreatorInterface
    {
        public function __construct(public int $bytes) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function create(string $sourcePath, string $zipPath, string $password, array $excludedFiles = []): int
        {
            file_put_contents($zipPath, str_repeat('Z', $this->bytes));

            return 1;
        }
    };

    app()->instance(NotifierDatabaseService::class, new NotifierDatabaseService(
        app(ChunkedUploadService::class),
        $zip,
        $dumper,
        new NotifierLoggerService,
    ));

    app()->instance(NotifierStorageService::class, new NotifierStorageService(
        app(ChunkedUploadService::class),
        $zip,
        new NotifierLoggerService,
    ));
}

it('runs at most once with a long timeout (no mid-backup retries)', function () {
    $job = new ProcessBackupJob(BackupTypeEnum::Database);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900);
});

it('carries the requested backup type', function (BackupTypeEnum $type) {
    expect((new ProcessBackupJob($type))->backupType)->toBe($type);
})->with([
    'database' => BackupTypeEnum::Database,
    'storage' => BackupTypeEnum::Storage,
]);

describe('handle() behaviour', function () {
    beforeEach(function () {
        Config::set('notifier.backup_code', 'test-code');
        Config::set('notifier.backup_url', 'https://test.com');
        Config::set('notifier.backup_zip_password', 'secret');

        // The upload protocol: init -> one chunk -> finalize (all 200 OK).
        Http::fake([
            'test.com/uploads/init' => Http::response(['upload_id' => 'up_123'], 200),
            'test.com/uploads/*/finalize' => Http::response(['status' => 'completed'], 200),
            'test.com/uploads/*' => Http::response(['ok' => true], 200),
        ]);

        Cache::forget(HeartbeatService::LAST_DATABASE_BACKUP_KEY);
        Cache::forget(HeartbeatService::LAST_STORAGE_BACKUP_KEY);
    });

    it('refuses to run with an incomplete environment (no unencrypted upload via direct dispatch)', function () {
        Config::set('notifier.backup_zip_password', '');

        $job = new ProcessBackupJob(BackupTypeEnum::Database);

        expect(fn () => app()->call([$job, 'handle']))
            ->toThrow(RuntimeException::class, 'NOTIFIER_BACKUP_PASSWORD');

        Http::assertNothingSent();
    });

    it('stamps the heartbeat timestamp after a successful queued database backup', function () {
        bindJobFakeBackupServices();

        app()->call([new ProcessBackupJob(BackupTypeEnum::Database), 'handle']);

        expect(Cache::get(HeartbeatService::LAST_DATABASE_BACKUP_KEY))->toBeString();

        // The uploaded archive name carries the type prefix and a random
        // per-run suffix - the guarantee that no two runs (of either type)
        // can collide on a path in storage/app/private.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/uploads/init')
            && preg_match('/^backup-database-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[0-9a-f]{8}\.zip$/', (string) $request['filename']) === 1);
    });

    it('stamps the heartbeat timestamp after a successful queued storage backup', function () {
        File::ensureDirectoryExists(storage_path('app/public'));
        bindJobFakeBackupServices();

        app()->call([new ProcessBackupJob(BackupTypeEnum::Storage), 'handle']);

        expect(Cache::get(HeartbeatService::LAST_STORAGE_BACKUP_KEY))->toBeString();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/uploads/init')
            && preg_match('/^backup-storage-\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}-[0-9a-f]{8}\.zip$/', (string) $request['filename']) === 1);
    });

    it('skips the upload and leaves the heartbeat unstamped when the storage archive is empty', function () {
        File::ensureDirectoryExists(storage_path('app/public'));
        // An archive below the min_storage_backup_bytes floor is treated as
        // "nothing to back up" by the service.
        bindJobFakeBackupServices(storageArchiveBytes: 10);

        app()->call([new ProcessBackupJob(BackupTypeEnum::Storage), 'handle']);

        expect(Cache::get(HeartbeatService::LAST_STORAGE_BACKUP_KEY))->toBeNull();
        Http::assertNothingSent();
    });
});
