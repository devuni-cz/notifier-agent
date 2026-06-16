<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;

describe('NotifierInstallCommand env escaping', function () {
    beforeEach(function () {
        $this->basePath = sys_get_temp_dir().'/notifier-install-test-'.uniqid();
        File::ensureDirectoryExists($this->basePath);
        file_put_contents($this->basePath.'/.env', 'APP_NAME=Testing'.PHP_EOL);

        $this->app->setBasePath($this->basePath);
    });

    afterEach(function () {
        File::deleteDirectory($this->basePath);
    });

    it('writes values containing quotes, spaces and backslashes escaped and double-quoted', function () {
        $this->artisan('notifier:install')
            ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'code "with" quotes')
            ->expectsQuestion('NOTIFIER_URL', 'https://example.com/a b')
            ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
            ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'pa\\ss "word" longtail')
            ->assertExitCode(0);

        $envContent = file_get_contents(base_path('.env'));

        expect($envContent)
            ->toContain('NOTIFIER_BACKUP_CODE="code \\"with\\" quotes"')
            ->toContain('NOTIFIER_URL="https://example.com/a b"')
            ->toContain('NOTIFIER_BACKUP_PASSWORD="pa\\\\ss \\"word\\" longtail"');

        // The written file must round-trip: a dotenv parser reads back the
        // exact values the user entered.
        $parsed = Dotenv::parse($envContent);

        expect($parsed['NOTIFIER_BACKUP_CODE'])->toBe('code "with" quotes');
        expect($parsed['NOTIFIER_URL'])->toBe('https://example.com/a b');
        expect($parsed['NOTIFIER_BACKUP_PASSWORD'])->toBe('pa\\ss "word" longtail');
    });

    it('replaces existing keys idempotently on re-run with --force, keeping escaping intact', function () {
        file_put_contents($this->basePath.'/.env', implode(PHP_EOL, [
            'APP_NAME=Testing',
            'NOTIFIER_BACKUP_CODE="old-code"',
            'NOTIFIER_URL="https://old.example.com"',
            'NOTIFIER_BACKUP_PASSWORD="old-password"',
        ]).PHP_EOL);

        $this->artisan('notifier:install', ['--force' => true])
            ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'new \\ "code"')
            ->expectsQuestion('NOTIFIER_URL', 'https://new.example.com')
            ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
            ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'C:\\secret\\path\\longer')
            ->assertExitCode(0);

        $envContent = file_get_contents(base_path('.env'));

        // The old lines were replaced in place - no duplicated keys.
        expect(mb_substr_count($envContent, 'NOTIFIER_BACKUP_CODE='))->toBe(1);
        expect(mb_substr_count($envContent, 'NOTIFIER_URL='))->toBe(1);
        expect(mb_substr_count($envContent, 'NOTIFIER_BACKUP_PASSWORD='))->toBe(1);
        expect($envContent)->not->toContain('old-code');

        $parsed = Dotenv::parse($envContent);

        expect($parsed['NOTIFIER_BACKUP_CODE'])->toBe('new \\ "code"');
        expect($parsed['NOTIFIER_URL'])->toBe('https://new.example.com');
        expect($parsed['NOTIFIER_BACKUP_PASSWORD'])->toBe('C:\\secret\\path\\longer');
    });

    it('still detects an existing installation when values were written escaped', function () {
        $this->artisan('notifier:install')
            ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'code "with" quotes')
            ->expectsQuestion('NOTIFIER_URL', 'https://example.com')
            ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
            ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'pass\\word longer123')
            ->assertExitCode(0);

        // Re-running without --force refuses to overwrite.
        $this->artisan('notifier:install')->assertExitCode(1);
    });
});
