<?php

declare(strict_types=1);

use Devuni\Notifier\Commands\NotifierDatabaseBackupCommand;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/*
 * NotifierDatabaseService is a `final` class, so it cannot be replaced with a
 * Mockery double. Instead these tests bind a REAL service built from faked leaf
 * dependencies (an in-memory dumper, a stub zip creator) and fake the HTTP layer,
 * so handle() runs end-to-end without ever touching mysqldump, the network, or
 * a real Process.
 */

/**
 * Build a database service whose dump output we control, then bind it into the
 * container so the command's method injection picks it up.
 *
 *   - $dumpContent = string -> the dumper writes that content
 *   - $dumpContent = null   -> the dumper writes no file (triggers the guard)
 */
function bindFakeDatabaseService(?string $dumpContent = 'SQL DUMP CONTENT'): void
{
    $dumper = new class($dumpContent) implements DatabaseDumperInterface
    {
        public function __construct(public ?string $content) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function dump(string $outputPath): void
        {
            if ($this->content !== null) {
                file_put_contents($outputPath, $this->content);
            }
        }

        public function describe(): string
        {
            return 'fake-dumper 1.0';
        }
    };

    $zip = new class implements ZipCreatorInterface
    {
        public static function isAvailable(): bool
        {
            return true;
        }

        public function create(string $sourcePath, string $zipPath, string $password, array $excludedFiles = []): int
        {
            file_put_contents($zipPath, str_repeat('ZIP', 200));

            return 1;
        }
    };

    app()->instance(NotifierDatabaseService::class, new NotifierDatabaseService(
        app(ChunkedUploadService::class),
        $zip,
        $dumper,
        new NotifierLoggerService,
    ));
}

describe('NotifierDatabaseBackupCommand', function () {
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
    });

    describe('handle method', function () {
        it('executes successfully when environment is properly configured', function () {
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')->assertExitCode(0);
        });

        it('fails when required environment variables are missing', function () {
            Config::set('notifier.backup_code', '');
            Config::set('notifier.backup_url', '');
            Config::set('notifier.backup_zip_password', 'something');

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('The following environment variables are missing or empty:')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_CODE')
                ->expectsOutputToContain('• NOTIFIER_URL')
                ->assertExitCode(1);
        });

        it('handles single missing environment variable', function () {
            Config::set('notifier.backup_code', 'test-code');
            Config::set('notifier.backup_url', 'https://test.com');
            Config::set('notifier.backup_zip_password', '');

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('ERROR')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_PASSWORD')
                ->assertExitCode(1);
        });

        it('displays correct command signature and description', function () {
            $command = new NotifierDatabaseBackupCommand;

            expect($command->getName())->toBe('notifier:database-backup');
            expect($command->getDescription())->toBe('Command for creating a database backup');
        });
    });

    describe('integration with NotifierDatabaseService', function () {
        it('creates a zip backup and uploads it through the chunked upload protocol', function () {
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')->assertExitCode(0);

            // The full upload handshake was exercised: init + chunk + finalize.
            Http::assertSent(fn ($request) => str_contains($request->url(), '/uploads/init'));
            Http::assertSent(fn ($request) => str_contains($request->url(), '/finalize'));
        });

        it('surfaces exceptions from backup creation (handle has no try/catch)', function () {
            // A dumper that writes no file makes createDatabaseBackup() throw.
            bindFakeDatabaseService(null);

            expect(fn () => $this->artisan('notifier:database-backup'))
                ->toThrow(RuntimeException::class, 'SQL dump file was not created');
        });

        it('records the last database backup time for the heartbeat manifest on success', function () {
            Cache::forget(HeartbeatService::LAST_DATABASE_BACKUP_KEY);
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')->assertExitCode(0);

            expect(Cache::get(HeartbeatService::LAST_DATABASE_BACKUP_KEY))->toBeString();
        });
    });

    describe('output formatting', function () {
        it('displays the start and end backup markers', function () {
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('⚙️  STARTING NEW BACKUP ⚙️')
                ->expectsOutputToContain('✅ End of backup')
                ->assertExitCode(0);
        });

        it('shows the created backup path in the success message', function () {
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('✅ Backup file created successfully at: ')
                ->assertExitCode(0);
        });
    });
});
