<?php

declare(strict_types=1);

use Devuni\Notifier\Exceptions\TransactionalImportFailedException;
use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| What the command does after an import fails depends on what the importer can
| say about the database. A replay over a transactional failure could only
| lose data, so it is reserved for importers that report nothing better.
|--------------------------------------------------------------------------
*/

function bindFailingRestoreLeaves(?Throwable $importError = null, ?Throwable $inspectError = null): ArrayObject
{
    $calls = new ArrayObject;

    app()->instance(DatabaseImporterInterface::class, new class($calls, $importError, $inspectError) implements DatabaseImporterInterface
    {
        public function __construct(
            private readonly ArrayObject $calls,
            private readonly ?Throwable $importError,
            private readonly ?Throwable $inspectError,
        ) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function inspect(string $sqlPath): void
        {
            $this->calls[] = 'inspect';

            if ($this->inspectError instanceof Throwable) {
                throw $this->inspectError;
            }
        }

        public function import(string $sqlPath): void
        {
            $this->calls[] = 'import';

            if ($this->importError instanceof Throwable) {
                throw $this->importError;
            }
        }

        public function describe(): string
        {
            return 'fake-importer';
        }
    });

    app()->instance(DatabaseDumperInterface::class, new class($calls) implements DatabaseDumperInterface
    {
        public function __construct(private readonly ArrayObject $calls) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function dump(string $outputPath): void
        {
            $this->calls[] = 'dump';

            file_put_contents($outputPath, "-- snapshot\n");
        }

        public function describe(): string
        {
            return 'fake-dumper';
        }
    });

    return $calls;
}

/** @return list<string> */
function restoreFailureSnapshots(): array
{
    return glob(storage_path('app/notifier-restore/pre-restore-*.sql')) ?: [];
}

beforeEach(function () {
    Config::set('notifier.backup_url', 'https://test.com');
    Config::set('notifier.restore_token', 'the-restore-token');
    Config::set('notifier.backup_zip_password', '');

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

    File::deleteDirectory(storage_path('app/notifier-restore'));
});

afterEach(function () {
    File::deleteDirectory(storage_path('app/notifier-restore'));
});

it('inspects the dump before taking the snapshot', function () {
    $calls = bindFailingRestoreLeaves();

    $this->artisan('notifier:database-restore', ['--force' => true])
        ->assertExitCode(0);

    expect($calls->getArrayCopy())->toBe(['inspect', 'dump', 'import']);
});

it('takes no snapshot and imports nothing when the dump is refused', function () {
    $calls = bindFailingRestoreLeaves(inspectError: new RuntimeException('dump refused'));

    $this->artisan('notifier:database-restore', ['--force' => true])
        ->expectsOutputToContain('dump refused')
        ->doesntExpectOutputToContain('rolling the database back')
        ->assertExitCode(1);

    expect($calls->getArrayCopy())->toBe(['inspect'])
        ->and(restoreFailureSnapshots())->toBe([]);
});

it('does not replay the snapshot over a database the transaction left unchanged', function () {
    $calls = bindFailingRestoreLeaves(new TransactionalImportFailedException('import boom', databaseUnchanged: true));

    $this->artisan('notifier:database-restore', ['--force' => true])
        ->expectsOutputToContain('the database is unchanged')
        ->doesntExpectOutputToContain('rolling the database back')
        ->assertExitCode(1);

    expect($calls->getArrayCopy())->toBe(['inspect', 'dump', 'import'])
        ->and(restoreFailureSnapshots())->toBe([]);
});

it('keeps the snapshot and replays nothing when the state cannot be confirmed', function () {
    $calls = bindFailingRestoreLeaves(new TransactionalImportFailedException('import boom', databaseUnchanged: false));

    $this->artisan('notifier:database-restore', ['--force' => true])
        ->expectsOutputToContain('could not be confirmed')
        ->doesntExpectOutputToContain('the database is unchanged')
        ->doesntExpectOutputToContain('rolling the database back')
        ->assertExitCode(1);

    expect($calls->getArrayCopy())->toBe(['inspect', 'dump', 'import'])
        ->and(restoreFailureSnapshots())->toHaveCount(1);
});

it('reports the unchanged database with --no-snapshot too', function () {
    bindFailingRestoreLeaves(new TransactionalImportFailedException('import boom', databaseUnchanged: true));

    $this->artisan('notifier:database-restore', ['--force' => true, '--no-snapshot' => true])
        ->expectsOutputToContain('the database is unchanged')
        ->doesntExpectOutputToContain('may be left partially restored')
        ->assertExitCode(1);
});

it('says no snapshot exists when the state cannot be confirmed with --no-snapshot', function () {
    bindFailingRestoreLeaves(new TransactionalImportFailedException('import boom', databaseUnchanged: false));

    $this->artisan('notifier:database-restore', ['--force' => true, '--no-snapshot' => true])
        ->expectsOutputToContain('could not be confirmed, so nothing was replayed over it. No snapshot was taken')
        ->assertExitCode(1);

    expect(restoreFailureSnapshots())->toBe([]);
});
