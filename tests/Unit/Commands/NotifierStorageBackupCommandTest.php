<?php

declare(strict_types=1);

use Devuni\Notifier\Commands\NotifierStorageBackupCommand;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Devuni\Notifier\Services\NotifierStorageService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
 * NotifierStorageService is a `final` class and cannot be replaced with a Mockery
 * double. These tests bind a REAL service built from a faked zip creator and fake
 * the HTTP layer, so handle() runs end-to-end without touching a real archiver,
 * the network, or a Process.
 */

/**
 * Bind a storage service whose archiving behaviour we control.
 *
 *   - $throw = null               -> the zip creator writes a $bytes-long archive
 *   - $throw = RuntimeException    -> the zip creator throws it (propagates unless
 *                                     the message starts with "No files to backup")
 *   - $bytes < 100                 -> createStorageBackup() treats the archive as
 *                                     too small/corrupt and returns '' (no upload)
 */
function bindFakeStorageService(?RuntimeException $throw = null, int $bytes = 550): void
{
    $zip = new class($throw, $bytes) implements ZipCreatorInterface
    {
        public function __construct(public ?RuntimeException $throw, public int $bytes) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function create(string $sourcePath, string $zipPath, string $password, array $excludedFiles = []): int
        {
            if ($this->throw !== null) {
                throw $this->throw;
            }

            // >= 100 bytes is a valid archive; < 100 bytes is treated as corrupt.
            file_put_contents($zipPath, str_repeat('Z', $this->bytes));

            return 1;
        }
    };

    app()->instance(NotifierStorageService::class, new NotifierStorageService(
        app(ChunkedUploadService::class),
        $zip,
        new NotifierLoggerService,
    ));
}

describe('NotifierStorageBackupCommand', function () {
    beforeEach(function () {
        Config::set('notifier.backup_code', 'test-code');
        Config::set('notifier.backup_url', 'https://test.com');
        Config::set('notifier.backup_zip_password', 'secret');

        // createStorageBackup() requires a real, non-empty source directory.
        $source = storage_path('app/public');
        File::ensureDirectoryExists($source);
        file_put_contents($source.'/keep.txt', 'content');

        Http::fake([
            'test.com/uploads/init' => Http::response(['upload_id' => 'up_123'], 200),
            'test.com/uploads/*/finalize' => Http::response(['status' => 'completed'], 200),
            'test.com/uploads/*' => Http::response(['ok' => true], 200),
        ]);
    });

    describe('handle method', function () {
        it('executes successfully when environment is properly configured', function () {
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')->assertExitCode(0);
        });

        it('fails when required environment variables are missing', function () {
            Config::set('notifier.backup_code', '');
            Config::set('notifier.backup_url', '');
            Config::set('notifier.backup_zip_password', '');

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('Missing environment variables:')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_CODE')
                ->expectsOutputToContain('• NOTIFIER_URL')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_PASSWORD')
                ->assertExitCode(1);
        });

        it('handles subset of missing environment variables', function () {
            Config::set('notifier.backup_code', 'test-code');
            Config::set('notifier.backup_url', 'https://test.com');
            Config::set('notifier.backup_zip_password', '');

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('RESULT')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_PASSWORD')
                ->assertExitCode(1);
        });

        it('displays correct command signature and description', function () {
            $command = new NotifierStorageBackupCommand;

            expect($command->getName())->toBe('notifier:storage-backup');
            expect($command->getDescription())->toBe('Archive the public storage and upload an encrypted backup to the Notifier server');
        });
    });

    describe('integration with NotifierStorageService', function () {
        it('creates a storage archive and uploads it through the chunked upload protocol', function () {
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')->assertExitCode(0);

            Http::assertSent(fn ($request) => str_contains($request->url(), '/uploads/init'));
            Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize'));
        });

        it('reports a clean failure (exit 1) instead of a stack trace when backup creation throws', function () {
            bindFakeStorageService(new RuntimeException('zip archiver exploded'));

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('Backup failed: zip archiver exploded')
                ->assertExitCode(1);
        });

        it('skips with a warning (exit 0) and does not stamp the heartbeat when the source is empty', function () {
            Cache::forget(HeartbeatService::LAST_STORAGE_BACKUP_KEY);
            // A zip creator that reports "No files to backup" makes createStorageBackup() return ''.
            bindFakeStorageService(new RuntimeException('No files to backup in the source directory'));

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('Nothing to back up')
                ->assertExitCode(0);

            expect(Cache::get(HeartbeatService::LAST_STORAGE_BACKUP_KEY))->toBeNull();
        });

        it('skips with a warning (exit 0) and does not stamp the heartbeat when the archive is too small/corrupt', function () {
            Cache::forget(HeartbeatService::LAST_STORAGE_BACKUP_KEY);
            // A non-empty source that yields a <100-byte archive (truncated/corrupt
            // write) must NOT report success or stamp the heartbeat - the old code
            // silently skipped the upload yet still reported a green backup.
            bindFakeStorageService(bytes: 8);

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('Nothing to back up')
                ->assertExitCode(0);

            expect(Cache::get(HeartbeatService::LAST_STORAGE_BACKUP_KEY))->toBeNull();
            Http::assertNotSent(fn ($request) => str_contains($request->url(), '/uploads/init'));
        });

        it('records the last storage backup time for the heartbeat manifest on success', function () {
            Cache::forget(HeartbeatService::LAST_STORAGE_BACKUP_KEY);
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')->assertExitCode(0);

            expect(Cache::get(HeartbeatService::LAST_STORAGE_BACKUP_KEY))->toBeString();
        });
    });

    describe('output formatting', function () {
        it('displays the start and end backup markers', function () {
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('Creating backup archive')
                ->expectsOutputToContain('Backup uploaded successfully')
                ->assertExitCode(0);
        });

        it('shows the created backup path in the success message', function () {
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('Backup archive created')
                ->assertExitCode(0);
        });
    });

    describe('command properties', function () {
        it('has correct signature property', function () {
            $command = new NotifierStorageBackupCommand;

            expect($command->getName())->toBe('notifier:storage-backup');
        });

        it('has correct description property', function () {
            $command = new NotifierStorageBackupCommand;

            expect($command->getDescription())->toBe('Archive the public storage and upload an encrypted backup to the Notifier server');
        });
    });
});
