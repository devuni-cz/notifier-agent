<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Jobs\ProcessBackupJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;

/*
|--------------------------------------------------------------------------
| When notifier.queue_connection is not 'sync', the backup endpoint must
| dispatch ProcessBackupJob to that connection instead of running inline.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Config::set('notifier.backup_code', 'tok');
    Config::set('notifier.backup_url', 'https://control-plane.test/upload');
    Config::set('notifier.backup_zip_password', 'pw');
});

it('dispatches ProcessBackupJob to the configured queue when queue_connection is not sync', function () {
    Config::set('notifier.queue_connection', 'redis');
    Bus::fake();

    $this->postJson('/api/notifier/backup', ['type' => 'backup_database'], ['X-Notifier-Token' => 'tok'])
        ->assertOk()
        ->assertJsonPath('queued', true)
        ->assertJsonPath('backup_type', 'backup_database');

    Bus::assertDispatched(
        ProcessBackupJob::class,
        fn (ProcessBackupJob $job): bool => $job->backupType === BackupTypeEnum::Database
            && $job->connection === 'redis',
    );
});

it('dispatches a storage backup job for the storage type', function () {
    Config::set('notifier.queue_connection', 'database');
    Bus::fake();

    $this->postJson('/api/notifier/backup', ['type' => 'backup_storage'], ['X-Notifier-Token' => 'tok'])
        ->assertOk()
        ->assertJsonPath('queued', true);

    Bus::assertDispatched(
        ProcessBackupJob::class,
        fn (ProcessBackupJob $job): bool => $job->backupType === BackupTypeEnum::Storage,
    );
});

it('does not dispatch a job when queue_connection is sync (runs inline)', function () {
    Config::set('notifier.queue_connection', 'sync');
    Bus::fake();

    // Inline execution will try to run the real backup (and fail without a DB),
    // but the job must NOT be queued - that is all this asserts.
    try {
        $this->postJson('/api/notifier/backup', ['type' => 'backup_database'], ['X-Notifier-Token' => 'tok']);
    } catch (Throwable) {
        // ignore inline backup failure (no database in the test environment)
    }

    Bus::assertNotDispatched(ProcessBackupJob::class);
});
