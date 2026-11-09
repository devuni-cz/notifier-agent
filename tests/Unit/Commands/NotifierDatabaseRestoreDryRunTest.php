<?php

declare(strict_types=1);

use Devuni\Notifier\Services\NotifierRestoreService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| A dry run answers "would this backup restore?", so it must not report success
| for a dump the real import would refuse. These tests run the real importers:
| the inspection needs neither a database nor a client binary.
|--------------------------------------------------------------------------
*/

/**
 * Serve one bare .sql backup and point the restore at a connection of $driver.
 *
 * @return array{'--dry-run': true, '--id': int, '--accept-unverified': string} A bare dump has no seal, so a restore needs this approval.
 */
function serveDumpForDriver(string $dump, string $driver): array
{
    Config::set('notifier.database_connection', 'restore_target');
    Config::set('database.connections.restore_target', ['driver' => $driver, 'database' => 'app']);

    // download() treats anything under 100 bytes as a truncated transfer.
    $padded = str_repeat("-- padding\n", 20).$dump;

    Http::fake([
        'test.com/backups/*/download' => Http::response($padded, 200),
        'test.com/backups*' => Http::response(['data' => [[
            'id' => 1,
            'type' => 'backup_database',
            'name' => 'db.sql',
            'size' => mb_strlen($padded, '8bit'),
            'checksum' => null,
            'created_at' => null,
        ]]], 200),
    ]);

    return ['--dry-run' => true, '--id' => 1, '--accept-unverified' => hash('sha256', $padded)];
}

beforeEach(function () {
    Config::set('notifier.backup_url', 'https://test.com');
    Config::set('notifier.restore_token', 'the-restore-token');
    // Empty password -> a database backup is a bare .sql, no zip round trip needed.
    Config::set('notifier.backup_zip_password', '');

    File::deleteDirectory(storage_path('app/notifier-restore'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/notifier-restore'));
});

it('passes a dry run of a dump framed by the pg_dump restrict lines', function () {
    $options = serveDumpForDriver("\\restrict abc123\nSELECT 1;\n\\unrestrict abc123\n", 'pgsql');

    $this->artisan('notifier:database-restore', $options)
        ->expectsOutputToContain('Dry run - the database was NOT modified.')
        ->assertExitCode(0);
});

it('fails a dry run of a dump the real import would refuse', function (string $dump, string $driver) {
    $options = serveDumpForDriver($dump, $driver);

    $this->artisan('notifier:database-restore', $options)
        ->expectsOutputToContain('client directive')
        ->doesntExpectOutputToContain('Dry run - the database was NOT modified.')
        ->assertExitCode(1);

    // The extracted dump is a plaintext database copy - a refusal must not strand it.
    expect(glob(storage_path('app/notifier-restore/run-*')))->toBe([]);
})->with([
    'psql directive' => ["SELECT 1;\n\\! id\n", 'pgsql'],
    'mysql directive' => ["SELECT 1;\nsystem id\n", 'mysql'],
]);

describe('NotifierRestoreService::inspectDatabase', function () {
    beforeEach(function () {
        Config::set('notifier.database_connection', 'restore_target');
        Config::set('database.connections.restore_target', ['driver' => 'pgsql', 'database' => 'app']);

        $this->extracted = storage_path('app/notifier-restore/inspect');
        File::ensureDirectoryExists($this->extracted);
    });

    it('applies the same one-dump rule as the import', function (array $files, string $message) {
        foreach ($files as $file) {
            File::put($this->extracted.'/'.$file, "SELECT 1;\n");
        }

        expect(fn () => app(NotifierRestoreService::class)->inspectDatabase($this->extracted))
            ->toThrow(RuntimeException::class, $message);
    })->with([
        'no dump' => [[], 'No .sql dump found'],
        'two dumps' => [['a.sql', 'b.sql'], 'Expected exactly one .sql dump, found 2.'],
    ]);
});
