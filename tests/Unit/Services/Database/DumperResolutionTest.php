<?php

declare(strict_types=1);

use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Services\Database\LazyDatabaseDumper;
use Devuni\Notifier\Services\Database\MysqlDumper;
use Devuni\Notifier\Services\Database\PostgresDumper;

/**
 * Resolve the bound DatabaseDumperInterface and unwrap the lazy proxy to the
 * concrete driver-specific implementation (mirrors what notifier:check does).
 */
function resolveConcreteNotifierDumper(): DatabaseDumperInterface
{
    $dumper = app(DatabaseDumperInterface::class);

    return $dumper instanceof LazyDatabaseDumper ? $dumper->resolve() : $dumper;
}

describe('DatabaseDumperInterface resolution', function () {
    it('binds the interface as a LazyDatabaseDumper proxy', function () {
        expect(app(DatabaseDumperInterface::class))->toBeInstanceOf(LazyDatabaseDumper::class);
    });

    it('selects MysqlDumper for the mysql driver', function () {
        config([
            'database.connections.notifier_target' => ['driver' => 'mysql', 'database' => 'app'],
            'notifier.database_connection' => 'notifier_target',
        ]);

        expect(resolveConcreteNotifierDumper())->toBeInstanceOf(MysqlDumper::class);
    });

    it('selects MysqlDumper for the mariadb driver', function () {
        config([
            'database.connections.notifier_target' => ['driver' => 'mariadb', 'database' => 'app'],
            'notifier.database_connection' => 'notifier_target',
        ]);

        expect(resolveConcreteNotifierDumper())->toBeInstanceOf(MysqlDumper::class);
    });

    it('selects PostgresDumper for the pgsql driver', function () {
        config([
            'database.connections.notifier_target' => ['driver' => 'pgsql', 'database' => 'app'],
            'notifier.database_connection' => 'notifier_target',
        ]);

        expect(resolveConcreteNotifierDumper())->toBeInstanceOf(PostgresDumper::class);
    });

    it('falls back to the default Laravel connection when notifier.database_connection is empty', function () {
        config([
            'database.connections.fallback_conn' => ['driver' => 'pgsql', 'database' => 'app'],
            'database.default' => 'fallback_conn',
            'notifier.database_connection' => null,
        ]);

        expect(resolveConcreteNotifierDumper())->toBeInstanceOf(PostgresDumper::class);
    });

    it('throws for an unsupported driver (sqlite test connection)', function () {
        // The default test connection is sqlite, which has no dump strategy.
        expect(fn () => resolveConcreteNotifierDumper())
            ->toThrow(RuntimeException::class, "Unsupported database driver 'sqlite'");
    });

    it('throws when no connection is configured at all', function () {
        config(['notifier.database_connection' => '', 'database.default' => '']);

        expect(fn () => resolveConcreteNotifierDumper())
            ->toThrow(RuntimeException::class, 'No database connection configured');
    });

    it('throws when the configured connection is missing from config/database.php', function () {
        config(['notifier.database_connection' => 'ghost_connection']);

        expect(fn () => resolveConcreteNotifierDumper())
            ->toThrow(RuntimeException::class, 'is not configured in config/database.php');
    });
});
