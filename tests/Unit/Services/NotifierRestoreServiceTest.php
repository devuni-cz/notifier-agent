<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Services\NotifierRestoreService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Restore pulls this site's own data back down, so the load-bearing
| guarantees are: it uses the SEPARATE restore token (never the backup
| code), it refuses to run when that token is absent, and a failed or
| truncated download never leaves a bogus archive behind.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('notifier.backup_url', 'https://notifier.test/api/v1/repositories/123');
    config()->set('notifier.backup_code', 'the-upload-code');
    config()->set('notifier.restore_token', 'the-restore-token');

    $this->work = sys_get_temp_dir().'/restore-'.uniqid();
    File::ensureDirectoryExists($this->work);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('refuses to restore when no restore token is configured', function () {
    config()->set('notifier.restore_token', null);

    expect(fn () => app(NotifierRestoreService::class)->available(BackupTypeEnum::Database))
        ->toThrow(RuntimeException::class, 'NOTIFIER_RESTORE_TOKEN');
});

it('authenticates with the restore token, never the backup code', function () {
    Http::fake([
        '*' => Http::response(['data' => []], 200),
    ]);

    app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);

    Http::assertSent(function ($request) {
        expect($request->header('X-Notifier-Token')[0])->toBe('the-restore-token')
            ->not->toBe('the-upload-code');

        return true;
    });
});

it('lists available backups of the requested type', function () {
    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 9, 'type' => 'backup_database', 'name' => 'db.zip', 'size' => 1024, 'created_at' => '2026-07-22T10:00:00+00:00'],
        ]], 200),
    ]);

    $backups = app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);

    expect($backups)->toHaveCount(1)
        ->and($backups[0]['id'])->toBe(9);
});

it('resolves the newest backup when no id is given', function () {
    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 12, 'type' => 'backup_database', 'name' => 'newer.zip', 'size' => 10, 'created_at' => null],
            ['id' => 3, 'type' => 'backup_database', 'name' => 'older.zip', 'size' => 10, 'created_at' => null],
        ]], 200),
    ]);

    $backup = app(NotifierRestoreService::class)->resolve(BackupTypeEnum::Database);

    expect($backup['id'])->toBe(12);
});

it('refuses an id that is not among this site\'s backups', function () {
    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 12, 'type' => 'backup_database', 'name' => 'a.zip', 'size' => 10, 'created_at' => null],
        ]], 200),
    ]);

    expect(fn () => app(NotifierRestoreService::class)->resolve(BackupTypeEnum::Database, 999))
        ->toThrow(RuntimeException::class, 'was not found');
});

it('reports a clear error when the server has no backup at all', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    expect(fn () => app(NotifierRestoreService::class)->resolve(BackupTypeEnum::Storage))
        ->toThrow(RuntimeException::class, 'no backup_storage backup');
});

it('surfaces an authentication failure instead of pretending it listed nothing', function () {
    Http::fake(['*' => Http::response(['message' => 'Authentication failed'], 403)]);

    expect(fn () => app(NotifierRestoreService::class)->available(BackupTypeEnum::Database))
        ->toThrow(RuntimeException::class, 'HTTP 403');
});

it('deletes the sunk file when the download fails, so no error page masquerades as an archive', function () {
    Http::fake(['*' => Http::response('Authentication failed', 403)]);

    $destination = $this->work.'/archive.zip';

    expect(fn () => app(NotifierRestoreService::class)->download(5, $destination))
        ->toThrow(RuntimeException::class, 'HTTP 403');

    expect(File::exists($destination))->toBeFalse();
});

it('rejects a truncated download rather than failing later inside the unzip', function () {
    Http::fake(['*' => Http::response('tiny', 200)]);

    $destination = $this->work.'/archive.zip';

    expect(fn () => app(NotifierRestoreService::class)->download(5, $destination))
        ->toThrow(RuntimeException::class, 'empty or truncated');

    expect(File::exists($destination))->toBeFalse();
});

it('exposes a well-formed backup checksum and nulls a malformed one', function () {
    $validHash = str_repeat('a', 64);

    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 1, 'type' => 'backup_database', 'name' => 'good.zip', 'size' => 10, 'checksum' => $validHash, 'created_at' => null],
            ['id' => 2, 'type' => 'backup_database', 'name' => 'bad.zip', 'size' => 10, 'checksum' => 'not-a-real-hash', 'created_at' => null],
        ]], 200),
    ]);

    $backups = app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);

    expect($backups[0]['checksum'])->toBe($validHash)
        ->and($backups[1]['checksum'])->toBeNull();
});

it('accepts a download whose bytes match the server checksum', function () {
    $body = str_repeat('ARCHIVE-BYTES-', 20); // > 100 bytes
    $checksum = hash('sha256', $body);

    Http::fake(['*' => Http::response($body, 200)]);

    $destination = $this->work.'/archive.zip';

    app(NotifierRestoreService::class)->download(5, $destination, $checksum);

    expect(File::get($destination))->toBe($body);
});

it('rejects and deletes a download whose bytes do NOT match the server checksum', function () {
    $body = str_repeat('TAMPERED-BYTES-', 20);
    $wrongChecksum = hash('sha256', 'something-else-entirely');

    Http::fake(['*' => Http::response($body, 200)]);

    $destination = $this->work.'/archive.zip';

    expect(fn () => app(NotifierRestoreService::class)->download(5, $destination, $wrongChecksum))
        ->toThrow(RuntimeException::class, 'does not match the checksum');

    expect(File::exists($destination))->toBeFalse();
});

it('verifies against the X-Backup-Checksum header when the listing gave no checksum', function () {
    $body = str_repeat('HEADER-VERIFIED-', 20);
    $checksum = hash('sha256', $body);

    // No checksum passed in, but the download response carries one.
    Http::fake(['*' => Http::response($body, 200, ['X-Backup-Checksum' => $checksum])]);

    $destination = $this->work.'/archive.zip';

    app(NotifierRestoreService::class)->download(5, $destination, null);

    expect(File::get($destination))->toBe($body);
});

it('rejects a download when the listing checksum and the header checksum disagree', function () {
    $body = str_repeat('CONFLICTING-BYTES-', 20);
    $listingChecksum = hash('sha256', $body);
    $headerChecksum = hash('sha256', 'a different thing');

    Http::fake(['*' => Http::response($body, 200, ['X-Backup-Checksum' => $headerChecksum])]);

    $destination = $this->work.'/archive.zip';

    expect(fn () => app(NotifierRestoreService::class)->download(5, $destination, $listingChecksum))
        ->toThrow(RuntimeException::class, 'two disagreeing checksums');

    expect(File::exists($destination))->toBeFalse();
});

it('restores storage additively - it overwrites matching files but deletes nothing', function () {
    $extracted = $this->work.'/extracted';
    $target = $this->work.'/storage';
    File::ensureDirectoryExists($extracted.'/nested');
    File::ensureDirectoryExists($target);

    File::put($extracted.'/shared.txt', 'from-backup');
    File::put($extracted.'/nested/new.txt', 'new');
    File::put($target.'/shared.txt', 'current');
    File::put($target.'/untouched.txt', 'keep me');

    $written = app(NotifierRestoreService::class)->restoreStorage($extracted, $target);

    expect($written)->toBe(2)
        ->and(File::get($target.'/shared.txt'))->toBe('from-backup')
        ->and(File::get($target.'/nested/new.txt'))->toBe('new')
        ->and(File::get($target.'/untouched.txt'))->toBe('keep me');
});

it('refuses a storage restore that would drop a .php webshell, writing nothing', function () {
    $extracted = $this->work.'/extracted';
    $target = $this->work.'/storage';
    File::ensureDirectoryExists($extracted.'/uploads');
    File::ensureDirectoryExists($target);

    // A legitimate-looking file alongside a webshell the server tried to smuggle in.
    // The guard goes by extension, so the body stays inert: real webshell code here
    // makes antivirus on developer machines quarantine the fixture mid-test.
    File::put($extracted.'/uploads/photo.jpg', 'jpeg-bytes');
    File::put($extracted.'/uploads/shell.php', 'inert fixture');

    expect(fn () => app(NotifierRestoreService::class)->restoreStorage($extracted, $target))
        ->toThrow(RuntimeException::class, 'executable/interpretable file');

    // The guard validates the whole tree BEFORE writing anything, so even the
    // innocent file must not have landed - the restore is all-or-nothing.
    expect(File::exists($target.'/uploads/photo.jpg'))->toBeFalse()
        ->and(File::exists($target.'/uploads/shell.php'))->toBeFalse();
});

it('refuses a storage restore containing an .htaccess override file', function () {
    $extracted = $this->work.'/extracted';
    $target = $this->work.'/storage';
    File::ensureDirectoryExists($extracted);
    File::ensureDirectoryExists($target);

    File::put($extracted.'/.htaccess', "AddType application/x-httpd-php .jpg\n");

    expect(fn () => app(NotifierRestoreService::class)->restoreStorage($extracted, $target))
        ->toThrow(RuntimeException::class, 'server-override file');
});

it('stages a bare .sql download for import when it was approved on a host with no password', function () {
    config()->set('notifier.backup_zip_password', '');

    // A password-less DB backup is a bare .sql, not a ZIP. extract() must stage it.
    $archive = $this->work.'/archive.zip';
    File::put($archive, "-- dump\nCREATE TABLE t (id int);\nINSERT INTO t VALUES (1);\n");

    $service = app(NotifierRestoreService::class);
    $verified = $service->verify(BackupTypeEnum::Database, $archive, 7, true, hash_file('sha256', $archive));
    $extractedDir = $service->extract($verified, $this->work.'/extracted');

    $dumps = glob($extractedDir.'/*.sql');
    expect($dumps)->toHaveCount(1)
        ->and(File::get($dumps[0]))->toContain('CREATE TABLE t');
});

it('refuses a non-ZIP download when a backup password is configured', function () {
    config()->set('notifier.backup_zip_password', 'secret');

    $archive = $this->work.'/archive.zip';
    File::put($archive, "-- not a zip, just plaintext\nCREATE TABLE t;\n");

    expect(fn () => app(NotifierRestoreService::class)->verify(BackupTypeEnum::Database, $archive, 7, false, null))
        ->toThrow(RuntimeException::class, 'not a ZIP archive');
});

it('passes an approved ZIP through to an extractor', function () {
    config()->set('notifier.backup_zip_password', '');

    $archive = $this->work.'/archive.zip';
    $zip = new ZipArchive;
    $zip->open($archive, ZipArchive::CREATE);
    $zip->addFromString('dump.sql', 'CREATE TABLE t (id int);');
    $zip->close();

    $service = app(NotifierRestoreService::class);
    $verified = $service->verify(BackupTypeEnum::Database, $archive, 7, true, hash_file('sha256', $archive));
    $extractedDir = $service->extract($verified, $this->work.'/extracted');

    expect(File::get($extractedDir.'/dump.sql'))->toBe('CREATE TABLE t (id int);');
});

it('restores files inside directories named like version control', function () {
    $extracted = $this->work.'/extracted';
    $target = $this->work.'/storage';
    File::ensureDirectoryExists($extracted.'/CVS');
    File::ensureDirectoryExists($extracted.'/.git');

    File::put($extracted.'/CVS/report.pdf', 'pdf');
    File::put($extracted.'/.git/notes.txt', 'notes');

    $written = app(NotifierRestoreService::class)->restoreStorage($extracted, $target);

    expect($written)->toBe(2)
        ->and(File::get($target.'/CVS/report.pdf'))->toBe('pdf')
        ->and(File::get($target.'/.git/notes.txt'))->toBe('notes');
});

it('checks files inside directories named like version control', function () {
    $extracted = $this->work.'/extracted';
    File::ensureDirectoryExists($extracted.'/CVS');
    File::put($extracted.'/CVS/shell.php', 'inert fixture');

    expect(fn () => app(NotifierRestoreService::class)->assertStorageEntriesSafe($extracted))
        ->toThrow(RuntimeException::class, 'executable/interpretable file');
});

describe('the working directory of a run', function () {
    afterEach(function () {
        File::deleteDirectory(storage_path('app/notifier-restore'));
    });

    it('is new and empty every time', function () {
        $service = app(NotifierRestoreService::class);

        $first = $service->workDirectory();
        File::put($first.'/left-behind.txt', 'from an interrupted run');
        $second = $service->workDirectory();

        expect($second)->not->toBe($first)
            ->and(File::files($second))->toBe([]);
    });

    it('is private to the user running the restore', function () {
        expect(fileperms(app(NotifierRestoreService::class)->workDirectory()) & 0777)->toBe(0700);
    })->skipOnWindows();

    it('clears runs abandoned a day ago and leaves recent ones alone', function () {
        $service = app(NotifierRestoreService::class);
        $abandoned = $service->workDirectory();
        $recent = $service->workDirectory();
        touch($abandoned, now()->subDays(2)->getTimestamp());

        $service->workDirectory();

        expect(File::exists($abandoned))->toBeFalse()
            ->and(File::exists($recent))->toBeTrue();
    });
});

it('removes control characters from error messages the server sends', function () {
    Http::fake(['*' => Http::response(['message' => "boom\u{9B}31m\x1B[2Jdone"], 500)]);

    try {
        app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);
        $this->fail('Expected the listing to fail.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('boom')
            ->not->toContain("\u{9B}")
            ->not->toContain("\x1B");
    }
});

it('removes control characters and markup from what the server lists', function () {
    Http::fake(['*' => Http::response(['data' => [[
        'id' => 1,
        'type' => 'backup_database',
        'name' => "evil\x1b[2J<fg=red>name</>.zip",
        'size' => 10,
        'created_at' => "2026-01-01\x1b[2J",
    ], [
        'id' => 2,
        'type' => 'backup_database',
        'name' => 'backup-database-2026-01-01_02-00-00-a1b2c3d4.zip',
        'size' => 10,
        'created_at' => '2026-07-22T10:00:00+00:00',
    ]]], 200)]);

    [$hostile, $honest] = app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);

    expect($hostile['name'])->toMatch('/\A[A-Za-z0-9._ -]+\z/')
        ->and($hostile['created_at'])->toBeNull()
        ->and($honest['name'])->toBe('backup-database-2026-01-01_02-00-00-a1b2c3d4.zip')
        ->and($honest['created_at'])->toBe('2026-07-22T10:00:00+00:00');
});

it('delegates a snapshot rollback to the database importer', function () {
    $recorder = new class implements DatabaseImporterInterface
    {
        /** @var array<int, string> */
        public array $seen = [];

        public static function isAvailable(): bool
        {
            return true;
        }

        public function import(string $sqlPath): void
        {
            $this->seen[] = $sqlPath;
        }

        public function describe(): string
        {
            return 'fake-importer';
        }
    };

    app()->instance(DatabaseImporterInterface::class, $recorder);

    app(NotifierRestoreService::class)->restoreDatabaseFromFile('/snapshots/pre-restore.sql');

    expect($recorder->seen)->toBe(['/snapshots/pre-restore.sql']);
});
