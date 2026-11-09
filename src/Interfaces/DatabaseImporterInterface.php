<?php

declare(strict_types=1);

namespace Devuni\Notifier\Interfaces;

use RuntimeException;

interface DatabaseImporterInterface
{
    /**
     * Whether the underlying client binary is present on this host.
     */
    public static function isAvailable(): bool;

    /**
     * Refuse a dump that import() would refuse, without touching the database.
     * Needs neither the client binary nor a reachable server.
     *
     * @param  string  $sqlPath  Path to an uncompressed .sql file.
     *
     * @throws RuntimeException when the dump must not be imported.
     */
    public function inspect(string $sqlPath): void;

    /**
     * Import a plain SQL dump into the configured connection.
     *
     * Destructive: the dump is applied as-is, so existing objects it recreates
     * are replaced. Callers are responsible for confirmation and for taking a
     * safety snapshot first.
     *
     * @param  string  $sqlPath  Path to an uncompressed .sql file.
     *
     * @throws RuntimeException when the import fails.
     */
    public function import(string $sqlPath): void;

    /**
     * Human-readable client identification, used in CLI reports.
     */
    public function describe(): string;
}
