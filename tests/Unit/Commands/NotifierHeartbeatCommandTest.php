<?php

declare(strict_types=1);

use Devuni\Notifier\Commands\NotifierHeartbeatCommand;
use Devuni\Notifier\Services\HeartbeatService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

/*
 * HeartbeatService is a `final` class, so it cannot be replaced with a Mockery
 * double. These tests drive the real service and fake the HTTP boundary, so
 * handle() runs end-to-end without touching the network.
 */

describe('NotifierHeartbeatCommand', function () {
    beforeEach(function () {
        Config::set('notifier.features.heartbeat', true);
        Config::set('notifier.backup_url', 'https://test.com/api/v1/repositories/42');
        Config::set('notifier.backup_code', 'test-code');
    });

    it('exits 0 and POSTs the heartbeat on success', function () {
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->artisan('notifier:heartbeat')->assertExitCode(0);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/heartbeat')
            && $request->hasHeader('X-Notifier-Token', 'test-code'));
    });

    it('exits 1 when the server rejects the heartbeat', function () {
        Http::fake(['*' => Http::response('boom', 500)]);

        $this->artisan('notifier:heartbeat')->assertExitCode(1);
    });

    it('exits 0 and sends no POST when the feature is disabled', function () {
        Config::set('notifier.features.heartbeat', false);
        Http::fake(['*' => Http::response(['ok' => true], 200)]);

        $this->artisan('notifier:heartbeat')->assertExitCode(0);

        Http::assertNothingSent();
    });

    it('has the expected signature and description', function () {
        $command = new NotifierHeartbeatCommand;

        expect($command->getName())->toBe('notifier:heartbeat')
            ->and($command->getDescription())->toBe('Report agent status and identity to the Notifier server');
    });

    describe('manifest recap', function () {
        it('shows the reported identity fields on success', function () {
            Http::fake(['*' => Http::response(['ok' => true], 200)]);

            Artisan::call('notifier:heartbeat');
            $output = Artisan::output();

            expect($output)
                ->toContain('Agent version:')
                ->toContain('Queue:')
                ->toContain('Enabled features:')
                ->toContain('Last database backup:');
        });

        it('shows "never" for a backup that has not run yet', function () {
            Cache::forget(HeartbeatService::LAST_DATABASE_BACKUP_KEY);
            Cache::forget(HeartbeatService::LAST_STORAGE_BACKUP_KEY);
            Http::fake(['*' => Http::response(['ok' => true], 200)]);

            Artisan::call('notifier:heartbeat');
            $output = Artisan::output();

            expect($output)
                ->toContain('Last database backup: never')
                ->toContain('Last storage backup: never');
        });

        it('shows the stored last-backup timestamp in the recap', function () {
            Cache::forever(HeartbeatService::LAST_DATABASE_BACKUP_KEY, '2026-06-18T07:00:00+00:00');
            Http::fake(['*' => Http::response(['ok' => true], 200)]);

            Artisan::call('notifier:heartbeat');
            $output = Artisan::output();

            expect($output)->toContain('Last database backup: 2026-06-18T07:00:00+00:00');
        });
    });
});
