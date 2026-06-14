<?php

declare(strict_types=1);

use Devuni\Notifier\Commands\NotifierHeartbeatCommand;
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
            ->and($command->getDescription())->toBe('Send agent heartbeat + identity manifest to the control plane');
    });
});
