<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class PostgresImporter implements DatabaseImporterInterface
{
    /**
     * @param  string  $connection  Laravel database connection name (e.g. "pgsql").
     */
    public function __construct(
        private readonly string $connection,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        return self::binaryAvailable('ysqlsh') || self::binaryAvailable('psql');
    }

    public function import(string $sqlPath): void
    {
        $logger = $this->notifierLogger->get();

        if (! is_file($sqlPath)) {
            throw new RuntimeException('SQL dump not found at: '.$sqlPath);
        }

        // Refuse a dump carrying psql metacommands (\!, \copy, \i, ...) before a
        // single byte reaches the client - a tampered archive could otherwise run
        // a shell command as the app user via `\!`.
        SqlDirectiveGuard::assertPostgresSafe($sqlPath);

        $config = config("database.connections.{$this->connection}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection '{$this->connection}' is not configured.");
        }

        $database = $config['database'] ?? null;

        if (empty($database)) {
            throw new RuntimeException("Database connection '{$this->connection}' has no database name configured.");
        }

        $binary = $this->resolveBinary();

        $process = new Process($this->buildCommand($binary, $config, (string) $database, $sqlPath));
        // Restores of large dumps legitimately run for a long time.
        $process->setTimeout(null);
        // Password via env var (not argv) to keep it out of /proc/*/cmdline and `ps` output.
        $process->setEnv(['PGPASSWORD' => (string) ($config['password'] ?? '')]);
        $process->run();

        if (! $process->isSuccessful()) {
            $logger->error('❌ '.$binary.' import failed', [
                'exitCode' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('Database restore failed: '.$process->getErrorOutput());
        }
    }

    /**
     * Build the psql/ysqlsh argv. The password is intentionally absent here - it
     * is passed via the PGPASSWORD env var in import() so it never reaches the
     * process table.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function buildCommand(string $binary, array $config, string $database, string $sqlPath): array
    {
        $schema = (string) config('notifier.postgres_schema', 'public');

        return [
            $binary,
            // Do not read ~/.psqlrc: it can inject metacommands (\!, \set) into
            // every session, so a hostile home dir must not influence a restore.
            '--no-psqlrc',
            // Abort and roll back the whole restore on the first error rather
            // than leaving the schema half-applied.
            '--set=ON_ERROR_STOP=1',
            '--single-transaction',
            '--quiet',
            // Never block on an interactive password prompt - without this psql
            // waits forever (the process timeout is intentionally null for large
            // restores, so a prompt would hang the command indefinitely).
            '--no-password',
            '--username='.($config['username'] ?? ''),
            '--port='.($config['port'] ?? 5432),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--dbname='.$database,
            // PostgresDumper emits a PLAIN dump without --clean/--if-exists, so
            // replaying it into a non-empty database dies on the first
            // "relation already exists" - which, with ON_ERROR_STOP +
            // --single-transaction, rolls the entire restore back. Reset the
            // schema first so the dump always lands on a clean slate, matching
            // what mysqldump gives us for free via DROP TABLE IF EXISTS.
            // psql executes -c and -f in the order given (PostgreSQL 9.6+) and
            // --single-transaction wraps BOTH, so the drop is atomic with the
            // import: a failed restore rolls the drop back too and the old
            // database survives untouched.
            '--command=DROP SCHEMA IF EXISTS '.$schema.' CASCADE; CREATE SCHEMA '.$schema.';',
            '--file='.$sqlPath,
        ];
    }

    public function describe(): string
    {
        $binary = $this->resolveBinary();
        $process = new Process([$binary, '--version']);
        $process->run();

        if (! $process->isSuccessful()) {
            return $binary.' (version unknown)';
        }

        return mb_trim($process->getOutput());
    }

    private static function binaryAvailable(string $binary): bool
    {
        $process = new Process([$binary, '--version']);

        try {
            $process->run();
        } catch (Throwable) {
            return false;
        }

        return $process->isSuccessful();
    }

    /**
     * Resolve which Postgres-compatible restore client to use.
     *
     * Resolution order:
     * 1. Explicit config override (`notifier.postgres_restore_binary`)
     * 2. ysqlsh if installed (YugabyteDB fork - preferred for YSQL deployments)
     * 3. psql (standard PostgreSQL)
     *
     * @throws RuntimeException When no compatible binary is installed.
     */
    private function resolveBinary(): string
    {
        $override = config('notifier.postgres_restore_binary');

        if (! empty($override)) {
            if (! self::binaryAvailable((string) $override)) {
                throw new RuntimeException(
                    "Configured postgres_restore_binary '{$override}' is not available on PATH."
                );
            }

            return (string) $override;
        }

        if (self::binaryAvailable('ysqlsh')) {
            return 'ysqlsh';
        }

        if (self::binaryAvailable('psql')) {
            return 'psql';
        }

        throw new RuntimeException(
            'Neither ysqlsh nor psql is installed. '
            .'Install PostgreSQL client tools (e.g. `apt install postgresql-client`) '
            .'or YugabyteDB tools (https://docs.yugabyte.com).'
        );
    }
}
