<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use Devuni\Notifier\Exceptions\TransactionalImportFailedException;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use Generator;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Process;
use Throwable;

final readonly class PostgresImporter implements DatabaseImporterInterface
{
    /**
     * First minor release of each supported major version with restricted mode.
     */
    private const array RESTRICTED_MODE_SINCE = [13 => 22, 14 => 19, 15 => 14, 16 => 10, 17 => 6];

    private const int RESTRICTED_MODE_EVERY_RELEASE_FROM = 18;

    private const int CHUNK_BYTES = 65_536;

    /**
     * @param  string  $connection  Laravel database connection name (e.g. "pgsql").
     */
    public function __construct(
        private string $connection,
        private NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        return self::binaryAvailable('ysqlsh') || self::binaryAvailable('psql');
    }

    /**
     * Whether a client, judged by its `--version` line, has restricted mode.
     *
     * Only a stock psql release counts. Forks such as ysqlsh follow their own patch
     * history, so a version number says nothing about them, and prereleases are
     * left out as well.
     */
    public static function supportsRestrictedMode(?string $version): bool
    {
        if ($version === null || preg_match('/^psql \(PostgreSQL\) ([0-9]+)\.([0-9]+)(?:\s|$)/', $version, $matches) !== 1) {
            return false;
        }

        $major = (int) $matches[1];

        return $major >= self::RESTRICTED_MODE_EVERY_RELEASE_FROM
            || (int) $matches[2] >= (self::RESTRICTED_MODE_SINCE[$major] ?? PHP_INT_MAX);
    }

    public function inspect(string $sqlPath): void
    {
        SqlDirectiveGuard::assertPostgresSafe($sqlPath);
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
        $restrictLines = SqlDirectiveGuard::postgresRestrictLines($sqlPath);

        $config = config("database.connections.{$this->connection}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection '{$this->connection}' is not configured.");
        }

        $database = $config['database'] ?? null;

        if (empty($database)) {
            throw new RuntimeException("Database connection '{$this->connection}' has no database name configured.");
        }

        $binary = $this->resolveBinary();
        $restrictKey = self::supportsRestrictedMode($this->clientVersion($binary)) ? bin2hex(random_bytes(32)) : null;

        if ($restrictKey === null) {
            $logger->warning('⚠️ '.$binary.' has no restricted mode, restoring with the dump inspection as the only check. '
                .'PostgreSQL clients 13.22, 14.19, 15.14, 16.10, 17.6 and 18 or newer have it.');

            // Such a client reads the dump as it is, its own restrict lines included.
            $restrictLines = [];
        }

        // Only psql is known to roll DDL back with the transaction, so only psql
        // gets the comparison that can prove a failed import changed nothing.
        $schemaBefore = pathinfo($binary, PATHINFO_FILENAME) === 'psql'
            ? $this->schemaIdentity($binary, $config, (string) $database)
            : null;

        $copy = $restrictLines !== [] && PHP_OS_FAMILY === 'Windows' ? $this->copyWithoutLines($sqlPath, $restrictLines) : null;
        $streamed = $restrictLines !== [] && $copy === null;

        try {
            $process = new Process($this->buildCommand($binary, $config, (string) $database, $copy ?? ($streamed ? '-' : $sqlPath), $restrictKey));
            // Restores of large dumps legitimately run for a long time.
            $process->setTimeout(null);
            // Password via env var (not argv) to keep it out of /proc/*/cmdline and `ps` output.
            $process->setEnv(['PGPASSWORD' => (string) ($config['password'] ?? '')]);

            if ($streamed) {
                $process->setInput($this->withoutLines($sqlPath, $restrictLines));
            }

            try {
                $process->run();
            } catch (ProcessSignaledException) {
                // Killed mid-import: reported below like any other failed run.
            }
        } finally {
            if ($copy !== null) {
                @unlink($copy);
            }
        }

        if (! $process->isSuccessful()) {
            $logger->error('❌ '.$binary.' import failed', [
                'exitCode' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new TransactionalImportFailedException(
                'Database restore failed: '.$process->getErrorOutput(),
                $schemaBefore !== null && $schemaBefore === $this->schemaIdentity($binary, $config, (string) $database),
            );
        }
    }

    /**
     * Reads a script without the given byte ranges, in constant memory.
     *
     * A read error must surface as an exception: if the script just ended early,
     * the client would commit a partial restore.
     *
     * @param  list<array{0: int, 1: int}>  $lines  Offset and length of each range, in file order.
     * @return Generator<int, string>
     */
    public function withoutLines(string $sqlPath, array $lines): Generator
    {
        $handle = fopen($sqlPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open SQL dump for restore: '.$sqlPath);
        }

        try {
            $position = 0;

            foreach ($lines as [$offset, $length]) {
                yield from $this->readBytes($handle, $offset - $position);

                $position = $offset + $length;

                if (fseek($handle, $position) !== 0) {
                    throw new RuntimeException('Unable to read SQL dump for restore: '.$sqlPath);
                }
            }

            yield from $this->readBytes($handle, PHP_INT_MAX);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Build the psql/ysqlsh argv. The password is intentionally absent here - it
     * is passed via the PGPASSWORD env var in import() so it never reaches the
     * process table.
     *
     * @param  array<string, mixed>  $config
     * @param  string  $sqlPath  Script to run, or `-` to read it from standard input.
     * @param  string|null  $restrictKey  Private key that keeps psql in restricted mode.
     * @return list<string>
     */
    public function buildCommand(string $binary, array $config, string $database, string $sqlPath, ?string $restrictKey = null): array
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
            // Runs first, so the client refuses every directive for the whole
            // restore. The key is new for each run and known only to this process.
            ...($restrictKey === null ? [] : ['--command=\\restrict '.$restrictKey]),
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

        return $this->clientVersion($binary) ?? $binary.' (version unknown)';
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

    private function clientVersion(string $binary): ?string
    {
        $process = new Process([$binary, '--version']);
        $process->run();

        return $process->isSuccessful() ? mb_trim($process->getOutput()) : null;
    }

    /**
     * psql on Windows reads standard input in text mode, which rewrites line endings
     * and takes a 0x1A byte for the end of the script, so it has to get a real file.
     *
     * @param  list<array{0: int, 1: int}>  $lines
     */
    private function copyWithoutLines(string $sqlPath, array $lines): string
    {
        $copy = tempnam(dirname($sqlPath), 'restore_');
        $handle = $copy === false ? false : fopen($copy, 'wb');

        if ($copy === false || $handle === false) {
            throw new RuntimeException('Unable to create a working copy of the SQL dump next to: '.$sqlPath);
        }

        try {
            foreach ($this->withoutLines($sqlPath, $lines) as $chunk) {
                if (fwrite($handle, $chunk) !== mb_strlen($chunk, '8bit')) {
                    throw new RuntimeException('Unable to write a working copy of the SQL dump next to: '.$sqlPath);
                }
            }
        } catch (Throwable $exception) {
            fclose($handle);
            @unlink($copy);

            throw $exception;
        }

        fclose($handle);

        return $copy;
    }

    /**
     * @param  resource  $handle
     * @return Generator<int, string>
     */
    private function readBytes($handle, int $bytes): Generator
    {
        while ($bytes > 0) {
            $chunk = fread($handle, min($bytes, self::CHUNK_BYTES));

            if ($chunk === false) {
                throw new RuntimeException('Unable to read SQL dump for restore.');
            }

            if ($chunk === '') {
                return;
            }

            $bytes -= mb_strlen($chunk, '8bit');

            yield $chunk;
        }
    }

    /**
     * The OID of the schema the import drops first. A rolled-back import leaves it
     * as it was. --single-transaction alone cannot promise that: a plain dump with
     * large objects carries its own COMMIT, and everything before it stays applied,
     * the schema reset included, which recreates the schema under a new OID.
     *
     * @param  array<string, mixed>  $config
     * @return string|null Null when the server could not answer.
     */
    private function schemaIdentity(string $binary, array $config, string $database): ?string
    {
        $schema = str_replace("'", "''", (string) config('notifier.postgres_schema', 'public'));

        $process = new Process([
            $binary,
            '--no-psqlrc',
            '--no-password',
            '--tuples-only',
            '--no-align',
            '--username='.($config['username'] ?? ''),
            '--port='.($config['port'] ?? 5432),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--dbname='.$database,
            "--command=SELECT COALESCE(to_regnamespace('{$schema}')::oid::text, 'absent');",
        ]);
        $process->setEnv(['PGPASSWORD' => (string) ($config['password'] ?? '')]);

        try {
            $process->run();
        } catch (Throwable) {
            return null;
        }

        return $process->isSuccessful() ? mb_trim($process->getOutput()) : null;
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
