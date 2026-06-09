<?php

declare(strict_types=1);

use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Services\Database\LazyDatabaseDumper;
use Devuni\Notifier\Services\Database\MysqlDumper;
use Devuni\Notifier\Services\Database\PostgresDumper;
use Devuni\Notifier\Services\NotifierLoggerService;

/**
 * Run $fn with a PATH that contains ONLY the given fake executables, then restore
 * it. Each fake binary is a tiny shell script that responds to `--version` with
 * exit code 0, so binary-detection logic can be exercised deterministically
 * regardless of what is actually installed on the host / CI runner.
 *
 * @param  list<string>  $binaries
 */
function withFakeBinaries(array $binaries, Closure $fn): mixed
{
    $dir = sys_get_temp_dir().'/notifier_bin_'.bin2hex(random_bytes(6));
    mkdir($dir);

    foreach ($binaries as $name) {
        $file = $dir.'/'.$name;
        file_put_contents($file, "#!/bin/sh\necho \"{$name} (fake) 1.0\"\n");
        chmod($file, 0o755);
    }

    $original = getenv('PATH') ?: '';
    putenv('PATH='.$dir);

    try {
        return $fn();
    } finally {
        putenv('PATH='.$original);
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

function invokePostgresResolveBinary(): string
{
    $dumper = new PostgresDumper('pgsql', new NotifierLoggerService());
    $method = new ReflectionMethod($dumper, 'resolveBinary');
    $method->setAccessible(true);

    return $method->invoke($dumper);
}

describe('MysqlDumper::buildCommand', function () {
    beforeEach(function () {
        config(['notifier.excluded_tables' => []]);
    });

    it('builds the expected mysqldump argv', function () {
        $dumper = new MysqlDumper('mysql', new NotifierLoggerService());

        $command = $dumper->buildCommand(
            ['username' => 'backup_user', 'password' => 'p@ss', 'port' => '3307', 'host' => 'db.internal'],
            'shop',
            '/tmp/out.sql',
        );

        expect($command[0])->toBe('mysqldump');
        expect($command)->toContain('--single-transaction');
        expect($command)->toContain('--quick');
        expect($command)->toContain('--user=backup_user');
        expect($command)->toContain('--port=3307');
        expect($command)->toContain('--host=db.internal');
        expect($command)->toContain('--result-file=/tmp/out.sql');
        // The database name is the final positional argument.
        expect($command[array_key_last($command)])->toBe('shop');
    });

    it('never puts the password on the command line', function () {
        $dumper = new MysqlDumper('mysql', new NotifierLoggerService());

        $command = $dumper->buildCommand(
            ['username' => 'u', 'password' => 'topsecret', 'port' => 3306, 'host' => 'localhost'],
            'shop',
            '/tmp/out.sql',
        );

        $joined = implode(' ', $command);
        expect($joined)->not->toContain('topsecret');
        expect($joined)->not->toContain('--password');
    });

    it('prefixes plain excluded tables with the database and passes dotted names through', function () {
        config(['notifier.excluded_tables' => ['sessions', 'analytics.events']]);

        $dumper = new MysqlDumper('mysql', new NotifierLoggerService());
        $command = $dumper->buildCommand(['host' => 'localhost'], 'shop', '/tmp/out.sql');

        expect($command)->toContain('--ignore-table=shop.sessions');
        expect($command)->toContain('--ignore-table=analytics.events');
    });
});

describe('PostgresDumper::buildCommand', function () {
    beforeEach(function () {
        config(['notifier.excluded_tables' => [], 'notifier.postgres_schema' => 'public']);
    });

    it('builds the expected pg_dump argv', function () {
        $dumper = new PostgresDumper('pgsql', new NotifierLoggerService());

        $command = $dumper->buildCommand(
            'pg_dump',
            ['username' => 'pg_user', 'password' => 'p@ss', 'port' => 5433, 'host' => 'pg.internal'],
            'shop',
            '/tmp/out.sql',
        );

        expect($command[0])->toBe('pg_dump');
        expect($command)->toContain('--host=pg.internal');
        expect($command)->toContain('--port=5433');
        expect($command)->toContain('--username=pg_user');
        expect($command)->toContain('--dbname=shop');
        expect($command)->toContain('--file=/tmp/out.sql');
        expect($command)->toContain('--no-owner');
        expect($command)->toContain('--no-privileges');
        expect($command)->toContain('--no-password');
    });

    it('honors the configured binary name (e.g. ysql_dump)', function () {
        $dumper = new PostgresDumper('pgsql', new NotifierLoggerService());

        $command = $dumper->buildCommand('ysql_dump', ['host' => 'localhost'], 'shop', '/tmp/out.sql');

        expect($command[0])->toBe('ysql_dump');
    });

    it('never puts the password on the command line', function () {
        $dumper = new PostgresDumper('pgsql', new NotifierLoggerService());

        $command = $dumper->buildCommand(
            'pg_dump',
            ['username' => 'u', 'password' => 'topsecret', 'host' => 'localhost'],
            'shop',
            '/tmp/out.sql',
        );

        expect(implode(' ', $command))->not->toContain('topsecret');
    });

    it('prefixes plain excluded tables with the configured schema and passes dotted names through', function () {
        config(['notifier.postgres_schema' => 'reporting', 'notifier.excluded_tables' => ['sessions', 'audit.events']]);

        $dumper = new PostgresDumper('pgsql', new NotifierLoggerService());
        $command = $dumper->buildCommand('pg_dump', ['host' => 'localhost'], 'shop', '/tmp/out.sql');

        expect($command)->toContain('--exclude-table=reporting.sessions');
        expect($command)->toContain('--exclude-table=audit.events');
    });
});

describe('PostgresDumper::resolveBinary', function () {
    beforeEach(function () {
        config(['notifier.postgres_dump_binary' => null]);
    });

    it('prefers ysql_dump when both ysql_dump and pg_dump are available', function () {
        withFakeBinaries(['ysql_dump', 'pg_dump'], function () {
            expect(invokePostgresResolveBinary())->toBe('ysql_dump');
        });
    });

    it('falls back to pg_dump when ysql_dump is absent', function () {
        withFakeBinaries(['pg_dump'], function () {
            expect(invokePostgresResolveBinary())->toBe('pg_dump');
        });
    });

    it('throws when neither postgres binary is installed', function () {
        withFakeBinaries([], function () {
            expect(fn () => invokePostgresResolveBinary())
                ->toThrow(RuntimeException::class, 'Neither ysql_dump nor pg_dump');
        });
    });

    it('honors an explicit binary override when it is on PATH', function () {
        withFakeBinaries(['custom_dump'], function () {
            config(['notifier.postgres_dump_binary' => 'custom_dump']);
            expect(invokePostgresResolveBinary())->toBe('custom_dump');
        });
    });

    it('throws when the configured binary override is not on PATH', function () {
        withFakeBinaries([], function () {
            config(['notifier.postgres_dump_binary' => 'missing_dump']);
            expect(fn () => invokePostgresResolveBinary())
                ->toThrow(RuntimeException::class, 'is not available on PATH');
        });
    });
});

describe('LazyDatabaseDumper', function () {
    it('resolves the underlying dumper once and proxies dump()/describe()', function () {
        $inner = new class implements DatabaseDumperInterface
        {
            public int $dumpCalls = 0;

            public static function isAvailable(): bool
            {
                return true;
            }

            public function dump(string $outputPath): void
            {
                $this->dumpCalls++;
            }

            public function describe(): string
            {
                return 'inner-dumper 1.0';
            }
        };

        $resolverCalls = 0;
        $lazy = new LazyDatabaseDumper(function () use (&$resolverCalls, $inner): DatabaseDumperInterface {
            $resolverCalls++;

            return $inner;
        });

        expect($lazy->resolve())->toBe($inner);
        $lazy->resolve();
        $lazy->describe();
        $lazy->dump('/tmp/whatever.sql');

        expect($resolverCalls)->toBe(1);
        expect($inner->dumpCalls)->toBe(1);
        expect($lazy->describe())->toBe('inner-dumper 1.0');
    });

    it('always reports itself as available', function () {
        expect(LazyDatabaseDumper::isAvailable())->toBeTrue();
    });
});
