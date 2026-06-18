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
                ->expectsOutputToContain('Missing environment variables:')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_CODE')
                ->expectsOutputToContain('• NOTIFIER_URL')
                ->assertExitCode(1);
        });

        it('handles single missing environment variable', function () {
            Config::set('notifier.backup_code', 'test-code');
            Config::set('notifier.backup_url', 'https://test.com');
            Config::set('notifier.backup_zip_password', '');

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('RESULT')
                ->expectsOutputToContain('• NOTIFIER_BACKUP_PASSWORD')
                ->assertExitCode(1);
        });

        it('displays correct command signature and description', function () {
            $command = new NotifierDatabaseBackupCommand;

            expect($command->getName())->toBe('notifier:database-backup');
            expect($command->getDescription())->toBe('Dump the database and upload an encrypted backup to the Notifier server');
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

        it('reports a clean failure (exit 1) instead of a stack trace when backup creation throws', function () {
            // A dumper that writes no file makes createDatabaseBackup() throw.
            bindFakeDatabaseService(null);

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('SQL dump file was not created')
                ->assertExitCode(1);
        });

        it('records the last database backup time for the heartbeat manifest on success', function () {
            Cache::forget(HeartbeatService::LAST_DATABASE_BACKUP_KEY);
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')->assertExitCode(0);

            expect(Cache::get(HeartbeatService::LAST_DATABASE_BACKUP_KEY))->toBeString();
        });
    });

    describe('output formatting', function () {
        it('reports creation and a successful upload', function () {
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('Creating backup archive')
                ->expectsOutputToContain('Backup uploaded successfully')
                ->assertExitCode(0);
        });

        it('confirms the archive was created', function () {
            bindFakeDatabaseService();

            $this->artisan('notifier:database-backup')
                ->expectsOutputToContain('Backup archive created')
                ->assertExitCode(0);
        });
    });
});
