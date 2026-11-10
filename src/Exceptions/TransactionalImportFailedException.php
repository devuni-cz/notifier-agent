<?php

declare(strict_types=1);

namespace Devuni\Notifier\Exceptions;

use RuntimeException;

/**
 * An import that runs as one transaction failed.
 *
 * The caller must not replay a snapshot over the database after this. The snapshot
 * is a filtered copy (no excluded tables, no privileges), so a replay could only
 * lose what the transaction kept.
 */
final class TransactionalImportFailedException extends RuntimeException
{
    /**
     * @param  bool  $databaseUnchanged  True only when the importer proved it; false means unknown.
     */
    public function __construct(string $message, public readonly bool $databaseUnchanged)
    {
        parent::__construct($message);
    }
}
