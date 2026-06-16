<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

describe('NotifierSendBackupController', function () {
    beforeEach(function () {
        Config::set('notifier.backup_code', 'test-backup-code');
        // Inbound triggers now authenticate against the trigger secret.
        Config::set('notifier.trigger_secret', 'test-backup-code');
        Config::set('notifier.backup_url', 'https://test-backup.com/upload');
        Config::set('notifier.backup_zip_password', 'test-password');
        Http::fake(['*' => Http::response('', 200)]);
    });

    describe('request validation', function () {
        it('returns 422 when type field is missing', function () {
            $this->postJson('/api/notifier/backup', [], [
                'X-Notifier-Token' => 'test-backup-code',
            ])->assertStatus(422);
        });

        it('returns 422 when type field has invalid value', function () {
            $this->postJson('/api/notifier/backup', ['type' => 'invalid'], [
                'X-Notifier-Token' => 'test-backup-code',
            ])->assertStatus(422);
        });

        it('accepts backup_database as a valid type value', function () {
            $response = $this->postJson('/api/notifier/backup', ['type' => 'backup_database'], [
                'X-Notifier-Token' => 'test-backup-code',
            ]);

            expect($response->status())->not->toBe(422);
        });

        it('accepts backup_storage as a valid type value', function () {
            $response = $this->postJson('/api/notifier/backup', ['type' => 'backup_storage'], [
                'X-Notifier-Token' => 'test-backup-code',
            ]);

            expect($response->status())->not->toBe(422);
        });
    });

    describe('authentication', function () {
        it('returns a generic 403 when token header is missing', function () {
            $this->postJson('/api/notifier/backup', ['type' => 'database'])
                ->assertStatus(403)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Invalid authentication token.',
                ]);
        });

        it('returns a generic 403 when token header is wrong', function () {
            $this->postJson('/api/notifier/backup', ['type' => 'database'], [
                'X-Notifier-Token' => 'wrong-token',
            ])->assertStatus(403)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Invalid authentication token.',
                ]);
        });

        it('returns 200 when token header is correct', function () {
            $response = $this->postJson('/api/notifier/backup', ['type' => 'database'], [
                'X-Notifier-Token' => 'test-backup-code',
            ]);

            expect($response->status())->not->toBe(401);
            expect($response->status())->not->toBe(403);
        });
    });

    describe('trigger secret split', function () {
        it('accepts the trigger secret and rejects the backup code when the two differ', function () {
            Config::set('notifier.backup_code', 'backup-code-value');
            Config::set('notifier.trigger_secret', 'trigger-secret-value');

            // Inbound triggers authenticate against the trigger secret...
            $accepted = $this->postJson('/api/notifier/backup', ['type' => 'backup_database'], [
                'X-Notifier-Token' => 'trigger-secret-value',
            ]);
            expect($accepted->status())->not->toBe(403);

            // ...and the backup code must NOT authenticate them once split (the
            // server stores it hashed and can no longer present it).
            $this->postJson('/api/notifier/backup', ['type' => 'backup_database'], [
                'X-Notifier-Token' => 'backup-code-value',
            ])->assertStatus(403);
        });

        it('falls back to the backup code when no trigger secret is configured', function () {
            Config::set('notifier.backup_code', 'backup-code-value');
            Config::set('notifier.trigger_secret', null);

            $response = $this->postJson('/api/notifier/backup', ['type' => 'backup_database'], [
                'X-Notifier-Token' => 'backup-code-value',
            ]);
            expect($response->status())->not->toBe(403);
        });
    });

    describe('environment validation', function () {
        it('returns the same generic 403 when environment variables are missing and leaks no env names', function () {
            Config::set('notifier.backup_code', '');
            Config::set('notifier.backup_url', '');
            Config::set('notifier.backup_zip_password', '');

            $response = $this->postJson('/api/notifier/backup', ['type' => 'database'], [
                'X-Notifier-Token' => '',
            ]);

            // Identical to the wrong-token response: a misconfigured server
            // must not be distinguishable before authentication.
            $response->assertStatus(403)
                ->assertExactJson([
                    'success' => false,
                    'message' => 'Invalid authentication token.',
                ]);

            expect($response->getContent())
                ->not->toContain('missing_variables')
                ->not->toContain('NOTIFIER_BACKUP_CODE')
                ->not->toContain('NOTIFIER_BACKUP_PASSWORD')
                ->not->toContain('NOTIFIER_URL');
        });
    });

    describe('rate limiting', function () {
        it('throttles repeated requests with a wrong token with 429', function () {
            foreach (range(1, 10) as $attempt) {
                $this->postJson('/api/notifier/backup', ['type' => 'database'], [
                    'X-Notifier-Token' => 'wrong-token',
                ])->assertStatus(403);
            }

            // Before the reorder, throttle ran after token verification, so
            // invalid tokens returned 403 forever - an unlimited brute force.
            $this->postJson('/api/notifier/backup', ['type' => 'database'], [
                'X-Notifier-Token' => 'wrong-token',
            ])->assertStatus(429);
        });
    });
});
