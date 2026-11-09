<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| What a failed restore leaves in the database can only be shown by a real
| server, so these run real psql and pg_dump and only fake the HTTP layer.
|
| They need a DISPOSABLE cluster that trusts the local "postgres" user, and are
| skipped unless its port is given:
|
|   NOTIFIER_TEST_PGSQL_PORT=54411 vendor/bin/pest tests/Feature/PostgresFailedRestoreTest.php
|
| psql and pg_dump come from PATH, or from NOTIFIER_TEST_PGSQL_BIN. Set that on
| Windows: Symfony caches resolved executables per process, so the fake binaries
| of other tests would otherwise be picked up here.
|--------------------------------------------------------------------------
*/

function failedRestoreClient(string $name): string
{
    $directory = getenv('NOTIFIER_TEST_PGSQL_BIN');

    return $directory === false ? $name : mb_rtrim($directory, '/\\').DIRECTORY_SEPARATOR.$name;
}

function failedRestorePsql(string $database, string $sql): string
{
    $process = new Process([
        failedRestoreClient('psql'), '--no-psqlrc', '--no-align', '--tuples-only', '--quiet',
        '--host=127.0.0.1', '--port='.getenv('NOTIFIER_TEST_PGSQL_PORT'), '--username=postgres',
        '--dbname='.$database, '--command='.$sql,
    ]);
    $process->run();

    return $process->isSuccessful()
        ? mb_trim(str_replace("\r", '', $process->getOutput()))
        : 'error: '.mb_trim($process->getErrorOutput());
}

/** @return array<string, string> */
function failedRestoreState(string $database): array
{
    return [
        'tables' => failedRestorePsql($database, "SELECT string_agg(tablename, ',' ORDER BY tablename) FROM pg_tables WHERE schemaname = 'public'"),
        'excluded rows' => failedRestorePsql($database, 'SELECT count(*) FROM public.sessions'),
        'users' => failedRestorePsql($database, "SELECT string_agg(name, ',' ORDER BY name) FROM public.users"),
        'privileges' => failedRestorePsql($database, "SELECT relacl::text FROM pg_class WHERE oid = to_regclass('public.users')"),
    ];
}

function serveFailedRestoreDump(string $dump): void
{
    // download() treats anything under 100 bytes as a truncated transfer.
    $body = str_repeat("-- padding\n", 20).$dump;

    Http::fake([
        'test.com/backups/*/download' => fn () => Http::response($body, 200),
        'test.com/backups*' => fn () => Http::response(['data' => [[
            'id' => 1,
            'type' => 'backup_database',
            'name' => 'db.sql',
            'size' => mb_strlen($body, '8bit'),
            'checksum' => null,
            'created_at' => null,
        ]]], 200),
    ]);
}

/** @return list<string> */
function failedRestoreSnapshots(): array
{
    return glob(storage_path('app/notifier-restore/pre-restore-*.sql')) ?: [];
}

beforeEach(function () {
    if (getenv('NOTIFIER_TEST_PGSQL_PORT') === false) {
        $this->markTestSkipped('Set NOTIFIER_TEST_PGSQL_PORT to a disposable PostgreSQL cluster.');
    }

    $this->database = 'notifier_failed_restore_'.bin2hex(random_bytes(4));

    failedRestorePsql('postgres', 'CREATE DATABASE '.$this->database);
    failedRestorePsql('postgres', 'DO $$ BEGIN CREATE ROLE notifier_ro_reader; EXCEPTION WHEN duplicate_object THEN NULL; END $$');
    failedRestorePsql($this->database, "
        CREATE TABLE public.users (id int PRIMARY KEY, name text);
        INSERT INTO public.users VALUES (1, 'live-alice');
        CREATE TABLE public.sessions (id text PRIMARY KEY);
        INSERT INTO public.sessions VALUES ('s1'), ('s2');
        GRANT SELECT ON public.users TO notifier_ro_reader;
    ");

    Config::set('notifier.backup_url', 'https://test.com');
    Config::set('notifier.restore_token', 'the-restore-token');
    Config::set('notifier.backup_zip_password', '');
    Config::set('notifier.excluded_tables', ['sessions']);
    Config::set('notifier.postgres_dump_binary', failedRestoreClient('pg_dump'));
    Config::set('notifier.postgres_restore_binary', failedRestoreClient('psql'));
    Config::set('notifier.database_connection', 'failed_restore');
    Config::set('database.connections.failed_restore', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => (int) getenv('NOTIFIER_TEST_PGSQL_PORT'),
        'username' => 'postgres',
        'password' => '',
        'database' => $this->database,
    ]);

    File::deleteDirectory(storage_path('app/notifier-restore'));
});

afterEach(function () {
    if (getenv('NOTIFIER_TEST_PGSQL_PORT') === false) {
        return;
    }

    failedRestorePsql('postgres', 'DROP DATABASE IF EXISTS '.$this->database.' WITH (FORCE)');
    failedRestorePsql('postgres', 'DROP DATABASE IF EXISTS '.$this->database.'_source WITH (FORCE)');
    failedRestorePsql('postgres', 'DROP ROLE IF EXISTS notifier_ro_reader');
    File::deleteDirectory(storage_path('app/notifier-restore'));
});

it('leaves excluded tables and privileges alone when psql rolls the import back', function () {
    $before = failedRestoreState($this->database);
    serveFailedRestoreDump("CREATE TABLE public.half_done (id int);\nSELECT no_such_function();\n");

    $exitCode = Artisan::call('notifier:database-restore', ['--force' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('the database is unchanged')
        ->and(failedRestoreState($this->database))->toBe($before)
        ->and(failedRestoreSnapshots())->toBe([]);
});

it('leaves excluded tables and privileges alone when the dump is refused', function () {
    $before = failedRestoreState($this->database);
    serveFailedRestoreDump("SELECT 1;\n\\! echo planted\n");

    $exitCode = Artisan::call('notifier:database-restore', ['--force' => true]);

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())->toContain('client directive')
        ->and(failedRestoreState($this->database))->toBe($before)
        ->and(failedRestoreSnapshots())->toBe([]);
});

it('keeps the snapshot and replays nothing when a dump committed part of its work', function () {
    // A plain dump with large objects carries its own COMMIT, which ends the
    // transaction psql opened, so a later error cannot undo what came before it.
    $source = $this->database.'_source';
    failedRestorePsql('postgres', 'CREATE DATABASE '.$source);
    failedRestorePsql($source, "CREATE TABLE public.docs (id int PRIMARY KEY, body oid); INSERT INTO public.docs VALUES (1, lo_from_bytea(0, 'hello'));");

    $dumpPath = tempnam(sys_get_temp_dir(), 'notifier_lo_');
    (new Process([
        failedRestoreClient('pg_dump'), '--host=127.0.0.1', '--port='.getenv('NOTIFIER_TEST_PGSQL_PORT'), '--username=postgres',
        '--dbname='.$source, '--file='.$dumpPath, '--no-owner', '--no-privileges', '--no-password',
    ]))->mustRun();
    $dump = File::get($dumpPath);
    File::delete($dumpPath);

    expect($dump)->toContain("\nCOMMIT;\n");

    serveFailedRestoreDump($dump."SELECT no_such_function();\n");

    $exitCode = Artisan::call('notifier:database-restore', ['--force' => true]);
    $output = Artisan::output();

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('could not be confirmed')
        ->and($output)->not->toContain('the database is unchanged')
        ->and(failedRestorePsql($this->database, "SELECT string_agg(tablename, ',') FROM pg_tables WHERE schemaname = 'public'"))->toBe('docs')
        ->and(failedRestoreSnapshots())->toHaveCount(1)
        ->and(File::get(failedRestoreSnapshots()[0]))->toContain('live-alice');
});

it('still restores a genuine dump through psql', function () {
    $source = $this->database.'_source';
    failedRestorePsql('postgres', 'CREATE DATABASE '.$source);
    failedRestorePsql($source, "CREATE TABLE public.users (id int PRIMARY KEY, name text); INSERT INTO public.users VALUES (1, 'from-backup');");

    $dumpPath = tempnam(sys_get_temp_dir(), 'notifier_dump_');
    (new Process([
        failedRestoreClient('pg_dump'), '--host=127.0.0.1', '--port='.getenv('NOTIFIER_TEST_PGSQL_PORT'), '--username=postgres',
        '--dbname='.$source, '--file='.$dumpPath, '--no-owner', '--no-privileges', '--no-password',
    ]))->mustRun();
    serveFailedRestoreDump(File::get($dumpPath));
    File::delete($dumpPath);

    $exitCode = Artisan::call('notifier:database-restore', ['--force' => true]);

    expect($exitCode)->toBe(0)
        ->and(failedRestorePsql($this->database, 'SELECT name FROM public.users'))->toBe('from-backup')
        ->and(failedRestoreSnapshots())->toBe([]);
});
