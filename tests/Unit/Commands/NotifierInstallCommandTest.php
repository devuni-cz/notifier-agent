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
                ->expectsConfirmation('Keep the existing backup password?', 'no')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'new-password-1234')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->expectsOutputToContain('Configuration saved')
                ->assertExitCode(0);

            $envContent = file_get_contents($this->basePath.'/.env');
            expect($envContent)
                ->toContain('NOTIFIER_BACKUP_CODE="new-code"')
                ->toContain('NOTIFIER_URL="https://new-url.com"')
                ->toContain('NOTIFIER_BACKUP_PASSWORD="new-password-1234"')
                ->not->toContain('existing-code');
        });

        it('generates and displays a one-time backup password when the user opts in', function () {
            file_put_contents($this->basePath.'/.env', 'APP_NAME=Testing'.PHP_EOL);

            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'my-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://new-url.com')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'yes')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->expectsOutputToContain('Store this backup password securely')
                ->assertExitCode(0);

            $envContent = file_get_contents($this->basePath.'/.env');
            // The generated value is 48 hex chars (bin2hex of 24 random bytes).
            expect($envContent)->toMatch('/NOTIFIER_BACKUP_PASSWORD="[0-9a-f]{48}"/');
        });

        it('masks the secrets in the closing recap instead of echoing the plaintext', function () {
            file_put_contents($this->basePath.'/.env', 'APP_NAME=Testing'.PHP_EOL);

            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'super-secret-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://new-url.com')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'new-password-1234')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->expectsOutputToContain('Configuration saved')
                ->doesntExpectOutputToContain('super-secret-code')
                ->doesntExpectOutputToContain('new-password-1234')
                ->assertExitCode(0);
        });

        it('creates the .env file from .env.example when it is missing', function () {
            file_put_contents($this->basePath.'/.env.example', 'APP_NAME=Example'.PHP_EOL);

            expect(file_exists($this->basePath.'/.env'))->toBeFalse();

            $this->artisan('notifier:install')
                ->expectsConfirmation('Do you want to create .env from .env.example?', 'yes')
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'test-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://test.com')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'test-password-1234')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
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

        it('keeps the existing backup password on a --force re-run by default', function () {
            file_put_contents($this->basePath.'/.env', implode(PHP_EOL, [
                'APP_NAME=Testing',
                'NOTIFIER_BACKUP_CODE="existing-code"',
                'NOTIFIER_URL="https://existing.com"',
                'NOTIFIER_BACKUP_PASSWORD="precious-old-password"',
            ]).PHP_EOL);

            // The point of the keep-prompt: adding an optional credential via
            // --force must NOT silently rotate the password that encrypts all
            // already-uploaded archives.
            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'existing-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://existing.com')
                ->expectsConfirmation('Keep the existing backup password?', 'yes')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->assertExitCode(0);

            expect(file_get_contents($this->basePath.'/.env'))
                ->toContain('NOTIFIER_BACKUP_PASSWORD="precious-old-password"');
        });

        it('rejects the backup code or password pasted as the restore token', function () {
            expect(Devuni\Notifier\Commands\NotifierInstallCommand::restoreTokenError('the-code', 'the-code', 'the-pass'))
                ->toContain('backup code');
            expect(Devuni\Notifier\Commands\NotifierInstallCommand::restoreTokenError('the-pass', 'the-code', 'the-pass'))
                ->toContain('backup password');
            expect(Devuni\Notifier\Commands\NotifierInstallCommand::restoreTokenError('a-real-token', 'the-code', 'the-pass'))
                ->toBeNull();
        });

        it('writes the restore token to .env when the user opts in, masked in the recap', function () {
            file_put_contents($this->basePath.'/.env', 'APP_NAME=Testing'.PHP_EOL);

            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'my-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://new-url.com')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'yes')
                ->expectsConfirmation('Configure a restore token now?', 'yes')
                ->expectsQuestion('NOTIFIER_RESTORE_TOKEN', 'issued-restore-token-123')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->doesntExpectOutputToContain('issued-restore-token-123')
                ->assertExitCode(0);

            expect(file_get_contents($this->basePath.'/.env'))
                ->toContain('NOTIFIER_RESTORE_TOKEN="issued-restore-token-123"');
        });

        it('generates a dedicated trigger secret when the user opts in and warns about the control plane side', function () {
            file_put_contents($this->basePath.'/.env', 'APP_NAME=Testing'.PHP_EOL);

            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'my-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://new-url.com')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'yes')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'yes')
                ->expectsOutputToContain('Configure this trigger secret for the repository on the control plane too')
                ->assertExitCode(0);

            // 48 hex chars, same strength as the generated backup password.
            expect(file_get_contents($this->basePath.'/.env'))
                ->toMatch('/NOTIFIER_TRIGGER_SECRET="[0-9a-f]{48}"/');
        });

        it('leaves both optional credentials out of .env when skipped and hints that restore stays disabled', function () {
            file_put_contents($this->basePath.'/.env', 'APP_NAME=Testing'.PHP_EOL);

            $this->artisan('notifier:install', ['--force' => true])
                ->expectsQuestion('NOTIFIER_BACKUP_CODE', 'my-code')
                ->expectsQuestion('NOTIFIER_URL', 'https://new-url.com')
                ->expectsConfirmation('Generate a strong backup password automatically?', 'yes')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->expectsOutputToContain('Restore stays disabled until NOTIFIER_RESTORE_TOKEN is set')
                ->assertExitCode(0);

            $envContent = file_get_contents($this->basePath.'/.env');
            expect($envContent)
                ->not->toContain('NOTIFIER_RESTORE_TOKEN')
                ->not->toContain('NOTIFIER_TRIGGER_SECRET');
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
                ->expectsConfirmation('Generate a strong backup password automatically?', 'no')
                ->expectsQuestion('NOTIFIER_BACKUP_PASSWORD', 'fresh-password-12')
                ->expectsConfirmation('Configure a restore token now?', 'no')
                ->expectsConfirmation('Generate a dedicated inbound trigger secret?', 'no')
                ->assertExitCode(0);

            $envContent = file_get_contents($this->basePath.'/.env');
            expect($envContent)->toContain('NOTIFIER_BACKUP_PASSWORD="fresh-password-12"');
        });
    });
});
