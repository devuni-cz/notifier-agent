<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Jobs\ProcessBackupJob;

/*
|--------------------------------------------------------------------------
| ProcessBackupJob contract. The handle() body is a trivial 2-arm match over
| the (final) backup services; its dispatch is covered behaviourally in
| BackupQueueDispatchTest. Here we pin the queue contract that protects a
| long-running backup from being retried or timed out mid-flight.
|--------------------------------------------------------------------------
*/

it('runs at most once with a long timeout (no mid-backup retries)', function () {
    $job = new ProcessBackupJob(BackupTypeEnum::Database);

    expect($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(900);
});

it('carries the requested backup type', function (BackupTypeEnum $type) {
    expect((new ProcessBackupJob($type))->backupType)->toBe($type);
})->with([
    'database' => BackupTypeEnum::Database,
    'storage' => BackupTypeEnum::Storage,
]);
