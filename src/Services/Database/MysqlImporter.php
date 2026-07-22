<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use RuntimeException;
use Symfony\Component\Process\Process;

final class MysqlImporter implements DatabaseImporterInterface
{
    /**
     * @param  string  $connection  Laravel database connection name (e.g. "mysql").
     */
    public function __construct(
        private readonly string $connection,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public static function isAvailable(): bool
    {
        $process = new Process(['mysql', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    public function import(string $sqlPath): void
    {
        $logger = $this->notifierLogger->get();

        if (! is_file($sqlPath)) {
            throw new RuntimeException('SQL dump not found at: '.$sqlPath);
        }

        // Refuse a dump carrying mysql client directives (\!, system, source)
        // before piping it in - a tampered archive could otherwise run a shell
        // command as the app user.
        SqlDirectiveGuard::assertMysqlSafe($sqlPath);

        $config = config("database.connections.{$this->connection}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection '{$this->connection}' is not configured.");
        }

        $database = $config['database'] ?? null;

        if (empty($database)) {
            throw new RuntimeException("Database connection '{$this->connection}' has no database name configured.");
        }

        $handle = fopen($sqlPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open SQL dump for reading: '.$sqlPath);
        }

        try {
            $process = new Process($this->buildCommand($config, (string) $database));
            // Restores of large dumps legitimately run for a long time.
            $process->setTimeout(null);
            // Password via env var (not argv) to keep it out of /proc/*/cmdline and `ps` output.
            $process->setEnv(['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
            // Stream the dump instead of loading it into memory.
            $process->setInput($handle);
            $process->run();
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            $logger->error('❌ mysql import failed', [
                'exitCode' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('Database restore failed: '.$process->getErrorOutput());
        }
    }

    /**
     * Build the mysql argv. The password is intentionally absent here - it is
     * passed via the MYSQL_PWD env var in import() so it never reaches the process table.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function buildCommand(array $config, string $database): array
    {
        return [
            'mysql',
            // Without --force the client aborts on the first SQL error. Note this
            // does NOT make the restore atomic: a mysqldump interleaves DDL
            // (DROP/CREATE TABLE) with INSERTs, and every DDL statement implicitly
            // commits, so a failure mid-dump leaves the database half-applied. Full
            // atomicity is impossible in the mysql client; the command takes a
            // pre-restore snapshot and rolls back to it on failure instead. Do NOT
            // add --force here - it would half-apply and defeat the fail-fast.
            '--batch',
            // Block `LOAD DATA LOCAL INFILE` in the dump from reading arbitrary
            // local files through the client.
            '--skip-local-infile',
            '--user='.($config['username'] ?? ''),
            '--port='.($config['port'] ?? 3306),
            '--host='.($config['host'] ?? '127.0.0.1'),
            $database,
        ];
    }

    public function describe(): string
    {
        $process = new Process(['mysql', '--version']);
        $process->run();

        if (! $process->isSuccessful()) {
            return 'mysql (version unknown)';
        }

        return mb_trim($process->getOutput());
    }
}
