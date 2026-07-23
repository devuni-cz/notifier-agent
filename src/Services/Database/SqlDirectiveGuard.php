<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use RuntimeException;

/**
 * Refuses a restore dump that carries client-side directives.
 *
 * The dump handed to `psql -f` / `mysql` is fully server-controlled. Both clients
 * execute directives embedded in the script - `\!` / `system` run a shell command
 * as the app OS user (RCE, independent of the database role), `\i` / `\copy` /
 * `source` touch the local filesystem. A genuine plain `pg_dump` / `mysqldump`
 * NEVER emits these, so their presence means the archive was tampered with and the
 * restore must abort before a single byte reaches the client.
 *
 * The AES-256 archive password (enforced by the extractors) is the primary gate;
 * this is defense-in-depth for the case where an attacker also knows the password.
 */
final class SqlDirectiveGuard
{
    /**
     * A plain pg_dump interleaves SQL statements with `COPY ... FROM stdin;` data
     * blocks. Inside a block every `\`-led line is escaped table data (`\t`, `\N`,
     * `\\`, ...) and must be ignored; the lone `\.` line ends the block. OUTSIDE a
     * block a genuine dump has no `\`-led lines at all, so any backslash there is a
     * psql metacommand (`\!`, `\i`, `\o`, `\copy`, `\gexec`, ...).
     *
     * @throws RuntimeException When a psql metacommand is found outside a COPY block.
     */
    public static function assertPostgresSafe(string $sqlPath): void
    {
        $handle = self::open($sqlPath);

        try {
            $inCopyBlock = false;
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if ($inCopyBlock) {
                    if (mb_rtrim($line, "\r\n") === '\.') {
                        $inCopyBlock = false;
                    }

                    continue;
                }

                if (preg_match('/^\s*\\\\/', $line) === 1) {
                    self::reject('psql', $lineNumber);
                }

                if (preg_match('/^\s*COPY\b.+\bFROM\s+stdin\s*;/i', $line) === 1) {
                    $inCopyBlock = true;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * A plain mysqldump (INSERT format) keeps each statement on lines that begin
     * with a SQL keyword, a comment (`--`, `/*!`), or a backtick; it never starts a
     * line with a backslash or the client's `system` / `source` directives.
     *
     * @throws RuntimeException When a mysql client directive is found.
     */
    public static function assertMysqlSafe(string $sqlPath): void
    {
        $handle = self::open($sqlPath);

        try {
            $lineNumber = 0;

            while (($line = fgets($handle)) !== false) {
                $lineNumber++;

                if (preg_match('/^\s*(\\\\|system\s|source\s)/i', $line) === 1) {
                    self::reject('mysql', $lineNumber);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private static function open(string $sqlPath)
    {
        $handle = fopen($sqlPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Unable to open SQL dump for inspection: '.$sqlPath);
        }

        return $handle;
    }

    private static function reject(string $client, int $lineNumber): never
    {
        throw new RuntimeException(
            'Refusing to restore: the dump contains a '.$client.' client directive on line '.$lineNumber
            .' that a genuine backup never produces. The archive is corrupt or has been tampered with.'
        );
    }
}
