<?php

declare(strict_types=1);

use Devuni\Notifier\Enums\BackupTypeEnum;
use Devuni\Notifier\Services\NotifierRestoreService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Restore pulls this site's own data back down, so the load-bearing
| guarantees are: it uses the SEPARATE restore token (never the backup
| code), it refuses to run when that token is absent, and a failed or
| truncated download never leaves a bogus archive behind.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    config()->set('notifier.backup_url', 'https://notifier.test/api/v1/repositories/123');
    config()->set('notifier.backup_code', 'the-upload-code');
    config()->set('notifier.restore_token', 'the-restore-token');

    $this->work = sys_get_temp_dir().'/restore-'.uniqid();
    File::ensureDirectoryExists($this->work);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('refuses to restore when no restore token is configured', function () {
    config()->set('notifier.restore_token', null);

    expect(fn () => app(NotifierRestoreService::class)->available(BackupTypeEnum::Database))
        ->toThrow(RuntimeException::class, 'NOTIFIER_RESTORE_TOKEN');
});

it('authenticates with the restore token, never the backup code', function () {
    Http::fake([
        '*' => Http::response(['data' => []], 200),
    ]);

    app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);

    Http::assertSent(function ($request) {
        expect($request->header('X-Notifier-Token')[0])->toBe('the-restore-token')
            ->not->toBe('the-upload-code');

        return true;
    });
});

it('lists available backups of the requested type', function () {
    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 9, 'type' => 'backup_database', 'name' => 'db.zip', 'size' => 1024, 'created_at' => '2026-07-22T10:00:00+00:00'],
        ]], 200),
    ]);

    $backups = app(NotifierRestoreService::class)->available(BackupTypeEnum::Database);

    expect($backups)->toHaveCount(1)
        ->and($backups[0]['id'])->toBe(9);
});

it('resolves the newest backup when no id is given', function () {
    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 12, 'type' => 'backup_database', 'name' => 'newer.zip', 'size' => 10, 'created_at' => null],
            ['id' => 3, 'type' => 'backup_database', 'name' => 'older.zip', 'size' => 10, 'created_at' => null],
        ]], 200),
    ]);

    $backup = app(NotifierRestoreService::class)->resolve(BackupTypeEnum::Database);

    expect($backup['id'])->toBe(12);
});

it('refuses an id that is not among this site\'s backups', function () {
    Http::fake([
        '*' => Http::response(['data' => [
            ['id' => 12, 'type' => 'backup_database', 'name' => 'a.zip', 'size' => 10, 'created_at' => null],
        ]], 200),
    ]);

    expect(fn () => app(NotifierRestoreService::class)->resolve(BackupTypeEnum::Database, 999))
        ->toThrow(RuntimeException::class, 'was not found');
});

it('reports a clear error when the server has no backup at all', function () {
    Http::fake(['*' => Http::response(['data' => []], 200)]);

    expect(fn () => app(NotifierRestoreService::class)->resolve(BackupTypeEnum::Storage))
        ->toThrow(RuntimeException::class, 'no backup_storage backup');
});

it('surfaces an authentication failure instead of pretending it listed nothing', function () {
    Http::fake(['*' => Http::response(['message' => 'Authentication failed'], 403)]);

    expect(fn () => app(NotifierRestoreService::class)->available(BackupTypeEnum::Database))
        ->toThrow(RuntimeException::class, 'HTTP 403');
});

it('deletes the sunk file when the download fails, so no error page masquerades as an archive', function () {
    Http::fake(['*' => Http::response('Authentication failed', 403)]);

    $destination = $this->work.'/archive.zip';

    expect(fn () => app(NotifierRestoreService::class)->download(5, $destination))
        ->toThrow(RuntimeException::class, 'HTTP 403');

    expect(File::exists($destination))->toBeFalse();
});

it('rejects a truncated download rather than failing later inside the unzip', function () {
    Http::fake(['*' => Http::response('tiny', 200)]);

    $destination = $this->work.'/archive.zip';

    expect(fn () => app(NotifierRestoreService::class)->download(5, $destination))
        ->toThrow(RuntimeException::class, 'empty or truncated');

    expect(File::exists($destination))->toBeFalse();
});

it('restores storage additively - it overwrites matching files but deletes nothing', function () {
    $extracted = $this->work.'/extracted';
    $target = $this->work.'/storage';
    File::ensureDirectoryExists($extracted.'/nested');
    File::ensureDirectoryExists($target);

    File::put($extracted.'/shared.txt', 'from-backup');
    File::put($extracted.'/nested/new.txt', 'new');
    File::put($target.'/shared.txt', 'current');
    File::put($target.'/untouched.txt', 'keep me');

    $written = app(NotifierRestoreService::class)->restoreStorage($extracted, $target);

    expect($written)->toBe(2)
        ->and(File::get($target.'/shared.txt'))->toBe('from-backup')
        ->and(File::get($target.'/nested/new.txt'))->toBe('new')
        ->and(File::get($target.'/untouched.txt'))->toBe('keep me');
});
