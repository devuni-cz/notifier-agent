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
 * The one exception is the `\restrict <key>` / `\unrestrict <key>` pair that pg_dump
 * 13.22, 14.19, 15.14, 16.10, 17.6 and newer write around a plain dump. They only
 * switch psql's own metacommand lockdown on and off, so they are allowed, but only
 * as a whole line of exactly the directive and an alphanumeric key.
 *
 * The AES-256 archive password (enforced by the extractors) is the primary gate;
 * this is defense-in-depth for the case where an attacker also knows the password.
 */
final class SqlDirectiveGuard
{
    /**
     * Best-effort inspection of the script, see {@see PsqlScriptScanner}. A backslash
     * inside a string, comment, routine body or COPY block is treated as data;
     * anywhere else as a psql metacommand (`\!`, `\i`, `\o`, `\copy`, `\gexec`, ...).
     *
     * @throws RuntimeException When a metacommand is found or the script cannot be verified.
     */
    public static function assertPostgresSafe(string $sqlPath): void
    {
        self::postgresRestrictLines($sqlPath);
    }

    /**
     * Inspects the script like {@see assertPostgresSafe} and reports where pg_dump's
     * own restrict and unrestrict lines are. Only the inspection can tell them from
     * the same text stored in a string or a COPY block.
     *
     * @return list<array{0: int, 1: int}> Byte offset and length of each line, in file order.
     *
     * @throws RuntimeException When a metacommand is found or the script cannot be verified.
     */
    public static function postgresRestrictLines(string $sqlPath): array
    {
        $handle = self::open($sqlPath);

        try {
            return new PsqlScriptScanner($handle)->scan();
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

    public static function reject(string $client, int $lineNumber): never
    {
        throw new RuntimeException(
            'Refusing to restore: the dump contains a '.$client.' client directive on line '.$lineNumber
            .' that a genuine backup never produces. The archive is corrupt or has been tampered with.'
        );
    }

    public static function rejectUnverifiable(string $client, int $lineNumber): never
    {
        throw new RuntimeException(
            'Refusing to restore: line '.$lineNumber.' of the dump cannot be verified as free of '.$client
            .' client directives. The archive is corrupt or has been tampered with.'
        );
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
}
