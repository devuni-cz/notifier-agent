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
 *   - $throw = null               -> the zip creator writes a >=100 byte archive
 *   - $throw = RuntimeException    -> the zip creator throws it (propagates unless
 *                                     the message starts with "No files to backup")
 */
function bindFakeStorageService(?RuntimeException $throw = null): void
{
    $zip = new class($throw) implements ZipCreatorInterface
    {
        public function __construct(public ?RuntimeException $throw) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function create(string $sourcePath, string $zipPath, string $password, array $excludedFiles = []): int
        {
            if ($this->throw !== null) {
                throw $this->throw;
            }

            // Must be >= 100 bytes or sendStorageBackup() skips the upload.
            file_put_contents($zipPath, str_repeat('ZIP-ARCHIVE', 50));

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
                ->expectsOutputToContain('The following environment variables are missing or empty:')
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
                ->expectsOutputToContain('ERROR')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_PASSWORD')
                ->assertExitCode(1);
        });

        it('displays correct command signature and description', function () {
            $command = new NotifierStorageBackupCommand;

            expect($command->getName())->toBe('notifier:storage-backup');
            expect($command->getDescription())->toBe('Command for creating a storage backup');
        });
    });

    describe('integration with NotifierStorageService', function () {
        it('creates a storage archive and uploads it through the chunked upload protocol', function () {
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')->assertExitCode(0);

            Http::assertSent(fn ($request) => str_contains($request->url(), '/uploads/init'));
            Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize'));
        });

        it('surfaces exceptions from backup creation (handle has no try/catch)', function () {
            bindFakeStorageService(new RuntimeException('zip archiver exploded'));

            expect(fn () => $this->artisan('notifier:storage-backup'))
                ->toThrow(RuntimeException::class, 'zip archiver exploded');
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
                ->expectsOutputToContain('⚙️  STARTING NEW BACKUP ⚙️')
                ->expectsOutputToContain('✅ End of backup')
                ->assertExitCode(0);
        });

        it('shows the created backup path in the success message', function () {
            bindFakeStorageService();

            $this->artisan('notifier:storage-backup')
                ->expectsOutputToContain('✅ Backup file created successfully at: ')
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

            expect($command->getDescription())->toBe('Command for creating a storage backup');
        });
    });
});
