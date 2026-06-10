<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'notifier.features.announcements' => true,
        'notifier.backup_url' => 'https://notifier.devuni.cz/api/v1/repositories/52740614',
        'notifier.backup_code' => 'super-secret-token',
    ]);
});

describe('<x-notifier-announcements-notice />', function () {
    it('renders the announcement content when an announcement is active', function () {
        Http::fake([
            '*/announcements' => Http::response([
                'announcements' => [
                    ['content' => 'Maintenance on 2026-06-30, ~5h downtime.', 'severity' => 'high'],
                ],
            ], 200),
        ]);

        $this->blade('<x-notifier-announcements-notice />')
            ->assertSee('Maintenance on 2026-06-30, ~5h downtime.')
            ->assertSee('notifier-announcement--high', false);
    });

    it('renders nothing when there are no active announcements', function () {
        Http::fake(['*/announcements' => Http::response(['announcements' => []], 200)]);

        $rendered = mb_trim($this->blade('<x-notifier-announcements-notice />')->__toString());

        expect($rendered)->toBe('');
    });

    it('renders nothing when the feature is disabled', function () {
        config(['notifier.features.announcements' => false]);
        Http::fake(['*' => Http::response(['announcements' => [['content' => 'x']]], 200)]);

        $rendered = mb_trim($this->blade('<x-notifier-announcements-notice />')->__toString());

        expect($rendered)->toBe('');
        Http::assertNothingSent();
    });
});
