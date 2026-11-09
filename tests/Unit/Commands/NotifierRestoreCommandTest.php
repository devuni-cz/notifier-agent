<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Interfaces\ZipExtractorInterface;
use Devuni\Notifier\Services\Zip\BackupSeal;
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

/** The options that approve exactly what the fake server is serving. */
function approvingOptions(): array
{
    return ['--id' => 1, '--accept-unverified' => hash('sha256', test()->download)];
}

/**
 * Bytes of a real encrypted archive, sealed unless a type is omitted.
 *
 * @param  array<string, string>  $entries
 */
function archiveBytes(array $entries, ?BackupTypeEnum $sealedAs): string
{
    $path = tempnam(sys_get_temp_dir(), 'seal');
    makeEncryptedArchive($path, config('notifier.backup_zip_password'), $entries);

    if ($sealedAs instanceof BackupTypeEnum) {
        (new BackupSeal)->seal($path, $sealedAs, config('notifier.backup_zip_password'), config('notifier.backup_url'));
    }

    $bytes = File::get($path);
    File::delete($path);

    return $bytes;
}

/** A plaintext ZIP, as a host with no backup password would have uploaded. */
function plainArchiveBytes(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'plain');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }

    $zip->close();
    $bytes = File::get($path);
    File::delete($path);

    return $bytes;
}

/** Replace the configured extractor with one that records how it was called. */
function bindRecordingExtractor(): object
{
    $extractor = new class implements ZipExtractorInterface
    {
        /** @var list<bool> */
        public array $sealFlags = [];

        public static function isAvailable(): bool
        {
            return true;
        }

        public function extract(string $zipPath, string $destination, string $password, bool $sealVerified = false): int
        {
            $this->sealFlags[] = $sealVerified;

            return 0;
        }
    };

    app()->instance(ZipExtractorInterface::class, $extractor);

    return $extractor;
}

function downloadWasRequested(): bool
{
    return Http::recorded(fn ($request) => str_contains($request->url(), '/download'))->isNotEmpty();
}

beforeEach(function () {
    Config::set('notifier.backup_url', 'https://test.com');
    Config::set('notifier.restore_token', 'the-restore-token');
    // Empty password -> a database backup is a bare .sql; extract() stages it
    // without needing a real 7z / ZipArchive round trip.
    Config::set('notifier.backup_zip_password', '');

    // A bare .sql download (non-ZIP, > 100 bytes) plus a one-row listing.
    $this->download = str_repeat("-- SQL DUMP LINE\n", 20);
    $this->downloadStatus = 200;

    Http::fake([
        'test.com/backups/*/download' => fn () => Http::response($this->download, $this->downloadStatus),
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

        $this->artisan('notifier:database-restore', ['--dry-run' => true, ...approvingOptions()])
            ->expectsOutputToContain('Dry run - the database was NOT modified.')
            ->doesntExpectOutputToContain('Refusing to restore in production')
            ->assertExitCode(0);

        expect($importer->calls)->toBe([]); // the DB was never touched
    });

    it('lets a storage dry-run run in production without --force', function () {
        app()->detectEnvironment(fn () => 'production');
        bindFakeRestoreLeaves();
        $this->download = plainArchiveBytes(['uploads/a.txt' => str_repeat('a', 200)]);

        $this->artisan('notifier:storage-restore', ['--dry-run' => true, ...approvingOptions()])
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

        expect(downloadWasRequested())->toBeFalse();
    });

    it('still refuses a real storage restore in production without --force', function () {
        app()->detectEnvironment(fn () => 'production');
        bindFakeRestoreLeaves();

        $this->artisan('notifier:storage-restore')
            ->expectsOutputToContain('Refusing to restore in production without --force.')
            ->assertExitCode(1);

        expect(downloadWasRequested())->toBeFalse();
    });
});

describe('command input', function () {
    it('refuses an --id that is not a positive whole number', function (string $command, string $id) {
        bindFakeRestoreLeaves();

        $this->artisan($command, ['--force' => true, '--id' => $id])
            ->expectsOutputToContain('--id must be a positive whole number')
            ->assertExitCode(1);

        expect(downloadWasRequested())->toBeFalse();
    })->with(['notifier:database-restore', 'notifier:storage-restore'])->with(['', 'abc', '#7', '0', '-1', '1.5']);

    it('prints markup in a server error as plain text', function (string $command) {
        bindFakeRestoreLeaves();
        $this->download = json_encode(['message' => '<href=https://evil.example>click</>']);
        $this->downloadStatus = 500;

        $this->artisan($command, ['--force' => true])
            ->expectsOutputToContain('<href=https://evil.example>click</>')
            ->assertExitCode(1);
    })->with(['notifier:database-restore', 'notifier:storage-restore']);

    it('leaves no working directory behind', function (string $command) {
        bindFakeRestoreLeaves();

        $this->artisan($command, ['--force' => true])
            ->assertExitCode(1);

        expect(glob(storage_path('app/notifier-restore/run-*')))->toBe([]);
    })->with(['notifier:database-restore', 'notifier:storage-restore']);
});

describe('database restore snapshot rollback', function () {
    it('deletes the snapshot after a successful restore', function () {
        $importer = bindFakeRestoreLeaves(importThrows: false);

        $this->artisan('notifier:database-restore', ['--force' => true, ...approvingOptions()])
            ->expectsOutputToContain('Database restored from backup #1')
            ->assertExitCode(0);

        expect($importer->calls)->toHaveCount(1)                    // only the main import
            ->and($importer->calls[0])->toContain('backup.sql')
            ->and(leftoverSnapshots())->toBe([]);                   // snapshot cleaned up
    });

    it('rolls back to the snapshot when the import fails', function () {
        $importer = bindFakeRestoreLeaves(importThrows: true, rollbackThrows: false);

        $this->artisan('notifier:database-restore', ['--force' => true, ...approvingOptions()])
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

        $this->artisan('notifier:database-restore', ['--force' => true, ...approvingOptions()])
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

        $this->artisan('notifier:database-restore', ['--force' => true, ...approvingOptions()])
            ->doesntExpectOutputToContain('Refusing to restore')
            ->assertExitCode(1);

        // The partial plaintext dump must NOT be left on disk.
        expect(leftoverSnapshots())->toBe([]);
    });

    it('skips rollback and warns when --no-snapshot is used and the import fails', function () {
        $importer = bindFakeRestoreLeaves(importThrows: true);

        $this->artisan('notifier:database-restore', ['--force' => true, '--no-snapshot' => true, ...approvingOptions()])
            ->expectsOutputToContain('No snapshot was taken (--no-snapshot); the database may be left partially restored.')
            ->assertExitCode(1);

        // Only the (failed) main import ran; no rollback import, no snapshot file.
        expect($importer->calls)->toHaveCount(1)
            ->and(leftoverSnapshots())->toBe([]);
    });
});

describe('restoring with a backup password', function () {
    beforeEach(function () {
        Config::set('notifier.backup_zip_password', 'a-long-enough-test-password');
        $this->dump = ['dump.sql' => 'CREATE TABLE t (id int);'];
    });

    it('restores a sealed database backup and says when it was sealed, even under --force', function () {
        $importer = bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, BackupTypeEnum::Database);

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('Authenticated database backup, sealed')
            ->expectsOutputToContain('Database restored from backup #1')
            ->assertExitCode(0);

        expect($importer->calls)->toHaveCount(1);
    });

    it('asks for confirmation after the archive has been verified', function () {
        $importer = bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, BackupTypeEnum::Database);

        $this->artisan('notifier:database-restore')
            ->expectsOutputToContain('Authenticated database backup, sealed')
            ->expectsQuestion('Type the database name to confirm', 'not-the-database')
            ->expectsOutputToContain('Confirmation did not match')
            ->assertExitCode(1);

        expect($importer->calls)->toBe([]);
    });

    it('does not ask anything about an archive that fails verification', function () {
        $importer = bindFakeRestoreLeaves();
        $bytes = archiveBytes($this->dump, BackupTypeEnum::Database);
        $bytes[40] = chr(ord($bytes[40]) ^ 1);
        $this->download = $bytes;

        $this->artisan('notifier:database-restore')
            ->expectsOutputToContain('does not match its seal')
            ->doesntExpectOutputToContain('OVERWRITE')
            ->assertExitCode(1);

        expect($importer->calls)->toBe([]);
    });

    it('refuses an unsealed archive and names the digest, even under --force', function () {
        $importer = bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, null);

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('--accept-unverified='.hash('sha256', $this->download))
            ->assertExitCode(1);

        expect($importer->calls)->toBe([]);
    });

    it('applies the same checks to a dry run', function () {
        bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, null);

        $this->artisan('notifier:database-restore', ['--dry-run' => true])
            ->expectsOutputToContain('has no seal')
            ->doesntExpectOutputToContain('Dry run - the database was NOT modified.')
            ->assertExitCode(1);
    });

    it('never hands a refused archive to an extractor', function () {
        bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, BackupTypeEnum::Storage);
        $extractor = bindRecordingExtractor();

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('different type')
            ->assertExitCode(1);

        expect($extractor->sealFlags)->toBe([]);
    });

    it('tells the configured extractor a sealed archive is already verified', function () {
        bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, BackupTypeEnum::Database);
        $extractor = bindRecordingExtractor();

        $this->artisan('notifier:database-restore', ['--dry-run' => true]);

        expect($extractor->sealFlags)->toBe([true]);
    });

    it('reads an unverified archive with the PHP extractor, whatever is configured', function () {
        bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, null);
        $extractor = bindRecordingExtractor();

        $this->artisan('notifier:database-restore', ['--dry-run' => true, ...approvingOptions()])
            ->expectsOutputToContain('Archive extracted')
            ->assertExitCode(0);

        expect($extractor->sealFlags)->toBe([]);
    });

    it('restores an approved unverified database archive and says it is unverified', function () {
        $importer = bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, null);

        $this->artisan('notifier:database-restore', ['--force' => true, ...approvingOptions()])
            ->expectsOutputToContain('UNVERIFIED')
            ->expectsOutputToContain('Database restored from backup #1')
            ->assertExitCode(0);

        expect($importer->calls)->toHaveCount(1);
    });

    it('refuses the newest backup once its seal is too old, and restores it by id', function () {
        $importer = bindFakeRestoreLeaves();
        $this->download = archiveBytes($this->dump, BackupTypeEnum::Database);

        $this->travel(36)->days();

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('re-run with --id=1')
            ->assertExitCode(1);

        expect($importer->calls)->toBe([]);

        $this->artisan('notifier:database-restore', ['--force' => true, '--id' => 1])
            ->assertExitCode(0);

        expect($importer->calls)->toHaveCount(1);
    });

    describe('storage', function () {
        beforeEach(function () {
            $this->folder = 'seal-test-'.uniqid();
            $this->public = storage_path('app/public/'.$this->folder);
        });

        afterEach(function () {
            File::deleteDirectory($this->public);
        });

        it('restores a sealed backup into public storage, whatever its files are called', function () {
            bindFakeRestoreLeaves();
            $this->download = archiveBytes([$this->folder.'/export.sql' => 'a user upload'], BackupTypeEnum::Storage);

            $this->artisan('notifier:storage-restore', ['--force' => true])
                ->expectsOutputToContain('Authenticated storage backup, sealed')
                ->assertExitCode(0);

            expect(File::get($this->public.'/export.sql'))->toBe('a user upload');
        });

        it('keeps an approved unverified restore out of public storage', function () {
            bindFakeRestoreLeaves();
            $this->download = archiveBytes([$this->folder.'/a.txt' => 'unverified'], null);

            $this->artisan('notifier:storage-restore', ['--force' => true, ...approvingOptions()])
                ->expectsOutputToContain('The unverified files are in:')
                ->assertExitCode(0);

            $copies = glob(storage_path('app/notifier-restore/unverified-storage-1-*'));

            expect($copies)->toHaveCount(1)
                ->and(File::get($copies[0].'/'.$this->folder.'/a.txt'))->toBe('unverified')
                ->and(File::exists($this->public.'/a.txt'))->toBeFalse()
                ->and(glob(storage_path('app/notifier-restore/run-*')))->toBe([]);

            if (PHP_OS_FAMILY !== 'Windows') {
                expect(fileperms($copies[0]) & 0777)->toBe(0700);
            }
        });

        it('makes no private copy of a blocked file type', function () {
            bindFakeRestoreLeaves();
            $this->download = archiveBytes([$this->folder.'/shell.php' => 'inert fixture'], null);

            $this->artisan('notifier:storage-restore', ['--force' => true, ...approvingOptions()])
                ->expectsOutputToContain('executable/interpretable')
                ->assertExitCode(1);

            expect(glob(storage_path('app/notifier-restore/unverified-storage-*')))->toBe([]);
        });

        it('refuses the newest backup once its seal is too old', function () {
            bindFakeRestoreLeaves();
            $this->download = archiveBytes([$this->folder.'/a.txt' => 'sealed'], BackupTypeEnum::Storage);

            $this->travel(36)->days();

            $this->artisan('notifier:storage-restore', ['--force' => true])
                ->expectsOutputToContain('re-run with --id=1')
                ->assertExitCode(1);

            expect(File::exists($this->public.'/a.txt'))->toBeFalse();
        });

        it('reports on a dry run what a real restore would refuse', function () {
            bindFakeRestoreLeaves();
            $this->download = archiveBytes([$this->folder.'/shell.php' => 'inert fixture'], BackupTypeEnum::Storage);

            $this->artisan('notifier:storage-restore', ['--dry-run' => true])
                ->expectsOutputToContain('executable/interpretable')
                ->doesntExpectOutputToContain('Dry run')
                ->assertExitCode(1);
        });

        it('makes no private copy on a dry run', function () {
            bindFakeRestoreLeaves();
            $this->download = archiveBytes([$this->folder.'/a.txt' => 'unverified'], null);

            $this->artisan('notifier:storage-restore', ['--dry-run' => true, ...approvingOptions()])
                ->expectsOutputToContain('Dry run')
                ->assertExitCode(0);

            expect(glob(storage_path('app/notifier-restore/unverified-storage-*')))->toBe([]);
        });
    });
});

describe('restoring with no backup password', function () {
    it('needs approval for a database dump', function () {
        $importer = bindFakeRestoreLeaves();

        $this->artisan('notifier:database-restore', ['--force' => true])
            ->expectsOutputToContain('--accept-unverified='.hash('sha256', $this->download))
            ->assertExitCode(1);

        expect($importer->calls)->toBe([]);
    });

    it('refuses a bare file as a storage backup, approved or not', function () {
        bindFakeRestoreLeaves();

        $this->artisan('notifier:storage-restore', ['--force' => true, ...approvingOptions()])
            ->expectsOutputToContain('must be a ZIP')
            ->assertExitCode(1);
    });
});
