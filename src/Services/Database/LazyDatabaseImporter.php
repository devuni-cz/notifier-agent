<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services\Database;

use Closure;
use Devuni\Notifier\Interfaces\DatabaseImporterInterface;

/**
 * Defers importer resolution until the first method call.
 *
 * Mirrors LazyDatabaseDumper: the service provider binds this proxy so that
 * container resolution doesn't eagerly fail when the configured driver is
 * unsupported (e.g. sqlite in test environments where nothing calls import()).
 */
final class LazyDatabaseImporter implements DatabaseImporterInterface
{
    private ?DatabaseImporterInterface $resolved = null;

    /**
     * @param  Closure(): DatabaseImporterInterface  $resolver
     */
    public function __construct(
        private readonly Closure $resolver,
    ) {}

    public static function isAvailable(): bool
    {
        // The proxy itself is always "available"; whether the resolved importer
        // is depends on which driver is configured. Use describe() / import() to
        // surface that.
        return true;
    }

    public function import(string $sqlPath): void
    {
        $this->resolve()->import($sqlPath);
    }

    public function describe(): string
    {
        return $this->resolve()->describe();
    }

    /**
     * Return the underlying importer (resolving it on first call).
     *
     * Useful for tests and the check command, which want to inspect the real
     * implementation without running an actual import.
     */
    public function resolve(): DatabaseImporterInterface
    {
        return $this->resolved ??= ($this->resolver)();
    }
}
