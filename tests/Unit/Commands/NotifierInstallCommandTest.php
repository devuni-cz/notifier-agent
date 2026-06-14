<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

describe('NotifierInstallCommand', function () {
    beforeEach(function () {
        $this->basePath = sys_get_temp_dir().'/notifier-install-cmd-test-'.uniqid();
        File::ensureDirectoryExists($this->basePath);
        $this->app->setBasePath($this->basePath);
    });

    afterEach(function () {
        File::deleteDirectory($this->basePath);
    });

    describe('handle method', function () {
        it('fails when configuration already exists without force flag', function () {
            file_put_contents($this->basePath.'/.env', implode(PHP_EOL, [
                'APP_NAME=Testing',
                'NOTIFIER_BACKUP_CODE="existing-code"',
                'NOTIFIER_URL="https://existing.com"',
                'NOTIFIER_BACKUP_PASSWORD="existing-pass"',
            ]).PHP_EOL);

            $this->artisan('notifier:install')
                ->expectsOutputToContain('already exists')
                ->assertExitCode(1);
        });

        it('overwrites the configuration when the force flag is provided', function () {
            file_put_contents($this->basePath.'/.env', implode(PHP_EOL, [
                'APP_NAME=Testing',
                'NOTIFIER_BACKUP_CODE="existing-code"',
                'NOTIFIER_URL="https://existing.com"',
                'NOTIFIER_BACKUP_PASSWORD="existing-pass"',
            ]).PHP_EOL);

            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'new-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://new-url.com')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'new-password')
                ->expectsOutputToContain('saved successfully')
                ->assertExitCode(0);

            $envContent = file_get_contents($this->basePath.'/.env');
            expect($envContent)
                ->toContain('NOTIFIER_BACKUP_CODE="new-code"')
                ->toContain('NOTIFIER_URL="https://new-url.com"')
                ->toContain('NOTIFIER_BACKUP_PASSWORD="new-password"')
                ->not->toContain('existing-code');
        });

        it('creates the .env file from .env.example when it is missing', function () {
            file_put_contents($this->basePath.'/.env.example', 'APP_NAME=Example'.PHP_EOL);

            expect(file_exists($this->basePath.'/.env'))->toBeFalse();

            $this->artisan('notifier:install')
                ->expectsConfirmation('Do you want to create .env from .env.example?', 'yes')
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'test-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://test.com')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'password')
                ->expectsOutputToContain('.env file has been created.')
                ->assertExitCode(0);

            expect(file_exists($this->basePath.'/.env'))->toBeTrue();
            $envContent = file_get_contents($this->basePath.'/.env');
            expect($envContent)
                ->toContain('APP_NAME=Example')
                ->toContain('NOTIFIER_BACKUP_CODE="test-code"');
        });

        it('aborts when creation of the .env file is declined', function () {
            file_put_contents($this->basePath.'/.env.example', 'APP_NAME=Example'.PHP_EOL);

            $this->artisan('notifier:install')
                ->expectsConfirmation('Do you want to create .env from .env.example?', 'no')
                ->expectsOutputToContain('Installation aborted')
                ->assertExitCode(1);

            expect(file_exists($this->basePath.'/.env'))->toBeFalse();
        });

        it('proceeds to prompts when an existing .env is missing one required key', function () {
            // Only two of the three required keys are present, so the install is
            // not considered complete and the command proceeds (without --force).
            file_put_contents($this->basePath.'/.env', implode(PHP_EOL, [
                'APP_NAME=Testing',
                'NOTIFIER_BACKUP_CODE="existing-code"',
                'NOTIFIER_URL="https://existing.com"',
            ]).PHP_EOL);

            $this->artisan('notifier:install')
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'fresh-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://fresh.com')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'fresh-password')
                ->assertExitCode(0);

            $envContent = file_get_contents($this->basePath.'/.env');
            expect($envContent)->toContain('NOTIFIER_BACKUP_PASSWORD="fresh-password"');
        });
    });
});
