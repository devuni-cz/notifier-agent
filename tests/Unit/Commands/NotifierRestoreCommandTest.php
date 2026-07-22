<?php

declare(strict_types=1);

use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The restore commands drive destructive operations against a live database /
| storage tree, so the load-bearing behaviours are: --dry-run must be usable
| in production (it destroys nothing), and a failed database import must roll
| back to the pre-restore snapshot rather than leave a half-applied database.
|
| The real NotifierRestoreService (final) runs end-to-end with faked leaves:
| an HTTP layer that lists + serves a bare .sql backup, an in-memory dumper
| for the snapshot, and an importer whose success/failure we control.
|--------------------------------------------------------------------------
*/

/**
 * Bind fake leaves so the real NotifierRestoreService runs without touching a
 * database or the network. Returns the importer so its calls can be asserted.
 *
 * The importer distinguishes the two paths by filename: the main restore imports
 * ".../extracted/backup.sql"; a rollback imports the ".../pre-restore-*.sql"
 * snapshot.
 */
function bindFakeRestoreLeaves(bool $importThrows = false, bool $rollbackThrows = false): object
{
    $importer = new class($importThrows, $rollbackThrows) implements DatabaseImporterInterface
    {
        /** @var array<int, string> */
        public array $calls = [];

        public function __construct(public bool $importThrows, public bool $rollbackThrows) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function import(string $sqlPath): void
        {
            $this->calls[] = $sqlPath;

            $isRollback = str_contains($sqlPath, 'pre-restore');

            if ($isRollback && $this->rollbackThrows) {
                throw new RuntimeException('rollback boom');
            }

            if (! $isRollback && $this->importThrows) {
                throw new RuntimeException('import boom');
            }
        }

        public function describe(): string
        {
            return 'fake-importer';
        }
    };

    app()->instance(DatabaseImporterInterface::class, $importer);

    app()->instance(DatabaseDumperInterface::class, new class implements DatabaseDumperInterface
    {
        public static function isAvailable(): bool
        {
            return true;
        }

        public function dump(string $outputPath): void
        {
            file_put_contents($outputPath, "-- snapshot\nCREATE TABLE t (id int);\n");
        }

        public function describe(): string
        {
            return 'fake-dumper';
        }
    });

    return $importer;
}

/** Glob the leftover pre-restore snapshots. */
function leftoverSnapshots(): array
{
    return glob(storage_path('app/notifier-restore/pre-restore-*.sql')) ?: [];
}

beforeEach(function () {
    Config::set('notifier.backup_url', 'https://test.com');
    Config::set('notifier.restore_token', 'the-restore-token');
    // Empty password -> a database backup is a bare .sql; extract() stages it
    // without needing a real 7z / ZipArchive round trip.
    Config::set('notifier.backup_zip_password', '');

    // A bare .sql download (non-ZIP, > 100 bytes) plus a one-row listing.
    Http::fake([
        'test.com/backups/*/download' => Http::response(str_repeat("-- SQL DUMP LINE\n", 20), 200),
        'test.com/backups*' => Http::response(['data' => [[
            'id' => 1,
            'type' => 'backup_database',
            'name' => 'db.sql',
            'size' => 340,
            'checksum' => null,
            'created_at' => null,
        ]]], 200),
    ]);

    // Never let one test's leftover snapshot leak into another's assertions.
    File::deleteDirectory(storage_path('app/notifier-restore'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/notifier-restore'));
});

describe('--dry-run production gate', function () {
    it('lets a database dry-run run in production without --force', function () {
        app()->detectEnvironment(fn () => 'production');
        $importer = bindFakeRestoreLeaves();

        $this->artisan('notifier:database-restore', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run - the database was NOT modified.')
            ->doesntExpectOutputToContain('Refusing to restore in production')
            ->assertExitCode(0);

        expect($importer->calls)->toBe([]); // the DB was never touched
    });

    it('lets a storage dry-run run in production without --force', function () {
        app()->detectEnvironment(fn () => 'production');
        bindFakeRestoreLeaves();

        $this->artisan('notifier:storage-restore', ['--dry-run' => true])
            ->expectsOutputToContain('Dry run - no files were written to storage.')
            ->doesntExpectOutputToContain('Refusing to restore in production')
            ->assertExitCode(0);
    });

    it('still refuses a real database restore in production without --force', function () {
        app()->detectEnvironment(fn () => 'production');
        bindFakeRestoreLeaves();

        $this->artisan('notifier:database-restore')
            ->expectsOutputToContain('Refusing to restore in production without --force.')
            ->assertExitCode(1);
    });
});

describe('database restore snapshot rollback', function () {
    it('deletes the snapshot after a successful restore', function () {
        $importer = bindFakeRestoreLeaves(importThrows: false);

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('Database restored from backup #1')
            ->assertExitCode(0);

        expect($importer->calls)->toHaveCount(1)                    // only the main import
            ->and($importer->calls[0])->toContain('backup.sql')
            ->and(leftoverSnapshots())->toBe([]);                   // snapshot cleaned up
    });

    it('rolls back to the snapshot when the import fails', function () {
        $importer = bindFakeRestoreLeaves(importThrows: true, rollbackThrows: false);

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('rolling the database back to the pre-restore snapshot')
            ->expectsOutputToContain('Database rolled back to its pre-restore state.')
            ->assertExitCode(1);

        // main import (threw) + rollback import of the snapshot
        expect($importer->calls)->toHaveCount(2)
            ->and($importer->calls[1])->toContain('pre-restore')
            ->and(leftoverSnapshots())->toBe([]);                   // deleted after a good rollback
    });

    it('keeps the snapshot on disk when the rollback itself fails', function () {
        bindFakeRestoreLeaves(importThrows: true, rollbackThrows: true);

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('ROLLBACK FAILED')
            ->assertExitCode(1);

        // The snapshot is the only remaining copy of the pre-restore data - kept.
        expect(leftoverSnapshots())->not->toBe([]);
    });

    it('leaves no orphaned snapshot when the snapshot dump itself fails', function () {
        // Importer never reached; the dumper writes a partial file then throws.
        app()->instance(DatabaseImporterInterface::class, new class implements DatabaseImporterInterface
        {
            public static function isAvailable(): bool
            {
                return true;
            }

            public function import(string $sqlPath): void {}

            public function describe(): string
            {
                return 'fake-importer';
            }
        });

        app()->instance(DatabaseDumperInterface::class, new class implements DatabaseDumperInterface
        {
            public static function isAvailable(): bool
            {
                return true;
            }

            public function dump(string $outputPath): void
            {
                file_put_contents($outputPath, 'PARTIAL DUMP');

                throw new RuntimeException('dump boom');
            }

            public function describe(): string
            {
                return 'fake-dumper';
            }
        });

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->assertExitCode(1);

        // The partial plaintext dump must NOT be left on disk.
        expect(leftoverSnapshots())->toBe([]);
    });

    it('skips rollback and warns when --no-snapshot is used and the import fails', function () {
        $importer = bindFakeRestoreLeaves(importThrows: true);

        $this->artisan('notifier:database-restore', ['--force' => true, '--no-snapshot' => true])
            ->expectsOutputToContain('No snapshot was taken (--no-snapshot); the database may be left partially restored.')
            ->assertExitCode(1);

        // Only the (failed) main import ran; no rollback import, no snapshot file.
        expect($importer->calls)->toHaveCount(1)
            ->and(leftoverSnapshots())->toBe([]);
    });
});
