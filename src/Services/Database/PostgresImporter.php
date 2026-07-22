<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use Devuni\Notifier\Interfaces\DatabaseImporterInterface;
use Devuni\Notifier\Services\NotifierLoggerService;
use RuntimeException;
use Symfony\Component\Process\Process;

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
        $process = new Process(['psql', '--version']);
        $process->run();

        return $process->isSuccessful();
    }

    public function import(string $sqlPath): void
    {
        $logger = $this->notifierLogger->get();

        if (! is_file($sqlPath)) {
            throw new RuntimeException('SQL dump not found at: '.$sqlPath);
        }

        $config = config("database.connections.{$this->connection}");

        if (! is_array($config)) {
            throw new RuntimeException("Database connection '{$this->connection}' is not configured.");
        }

        $database = $config['database'] ?? null;

        if (empty($database)) {
            throw new RuntimeException("Database connection '{$this->connection}' has no database name configured.");
        }

        $process = new Process($this->buildCommand($config, (string) $database, $sqlPath));
        // Restores of large dumps legitimately run for a long time.
        $process->setTimeout(null);
        // Password via env var (not argv) to keep it out of /proc/*/cmdline and `ps` output.
        $process->setEnv(['PGPASSWORD' => (string) ($config['password'] ?? '')]);
        $process->run();

        if (! $process->isSuccessful()) {
            $logger->error('❌ psql import failed', [
                'exitCode' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            throw new RuntimeException('Database restore failed: '.$process->getErrorOutput());
        }
    }

    /**
     * Build the psql argv. The password is intentionally absent here - it is
     * passed via the PGPASSWORD env var in import() so it never reaches the process table.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function buildCommand(array $config, string $database, string $sqlPath): array
    {
        return [
            'psql',
            // Abort and roll back the whole restore on the first error rather
            // than leaving the schema half-applied.
            '--set=ON_ERROR_STOP=1',
            '--single-transaction',
            '--quiet',
            '--username='.($config['username'] ?? ''),
            '--port='.($config['port'] ?? 5432),
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--dbname='.$database,
            '--file='.$sqlPath,
        ];
    }

    public function describe(): string
    {
        $process = new Process(['psql', '--version']);
        $process->run();

        if (! $process->isSuccessful()) {
            return 'psql (version unknown)';
        }

        return mb_trim($process->getOutput());
    }
}
