<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Database\PostgresImporter;
use Devuni\Notifier\Services\NotifierLoggerService;

/*
|--------------------------------------------------------------------------
| PostgresImporter must drive the RIGHT client binary: psql on standard
| PostgreSQL, ysqlsh on YugabyteDB. buildCommand() takes the resolved binary
| explicitly, so its argv is assertable without either binary installed.
|--------------------------------------------------------------------------
*/

it('builds the psql argv with the standard binary', function () {
    $importer = new PostgresImporter('pgsql', new NotifierLoggerService);

    $argv = $importer->buildCommand('psql', ['username' => 'u', 'port' => 5432, 'host' => 'h'], 'shop', '/tmp/d.sql');

    expect($argv[0])->toBe('psql')
        ->and($argv)->toContain('--dbname=shop')
        ->and($argv)->toContain('--file=/tmp/d.sql')
        ->and($argv)->toContain('--single-transaction')
        ->and($argv)->toContain('--no-psqlrc');
});

it('builds the ysqlsh argv for a YugabyteDB restore', function () {
    $importer = new PostgresImporter('pgsql', new NotifierLoggerService);

    $argv = $importer->buildCommand('ysqlsh', ['username' => 'yb', 'port' => 5433, 'host' => 'db'], 'shop', '/tmp/d.sql');

    expect($argv[0])->toBe('ysqlsh')
        ->and($argv)->toContain('--port=5433')
        ->and($argv)->toContain('--command=DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
});
