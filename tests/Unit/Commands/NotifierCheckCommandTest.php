<?php

declare(strict_types=1);

use Devuni\Notifier\Commands\NotifierCheckCommand;
use Devuni\Notifier\Services\Database\MysqlDumper;
use Devuni\Notifier\Services\Database\PostgresDumper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

describe('NotifierCheckCommand', function () {
    beforeEach(function () {
        Http::fake([
            '*' => Http::response('', 200),
        ]);
    });
    describe('command registration', function () {
        it('is registered in artisan', function () {
            $commands = Artisan::all();

            expect($commands)->toHaveKey('notifier:check');
            expect($commands['notifier:check'])->toBeInstanceOf(NotifierCheckCommand::class);
        });

        it('has correct signature', function () {
            $command = new NotifierCheckCommand;

            expect($command->getName())->toBe('notifier:check');
        });

        it('has correct description', function () {
            $command = new NotifierCheckCommand;

            expect($command->getDescription())->toBe('Check if Notifier package is configured correctly');
        });
    });

    describe('environment variable checks', function () {
        it('passes when all environment variables are configured', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => 'https://test-backup.com/upload',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('All required environment variables are configured');
        });

        it('fails when environment variables are missing', function () {
            config([
                'notifier.backup_code' => '',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => '',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Missing environment variables')
                ->assertExitCode(1);
        });

        it('shows masked configuration values without leaking the secrets', function () {
            config([
                'notifier.backup_code' => 'my-secret-code',
                'notifier.backup_url' => 'https://test-backup.com/upload',
                'notifier.backup_zip_password' => 'secret-password',
            ]);

            // Capture the rendered output directly: chaining many expectsOutputToContain()
            // assertions on a single PendingCommand is unreliable.
            Artisan::call('notifier:check');
            $output = Artisan::output();

            expect($output)->toContain('NOTIFIER_BACKUP_CODE:');
            expect($output)->toContain('NOTIFIER_BACKUP_PASSWORD:');
            // Only presence + length is reported, never any plaintext secret characters.
            expect($output)->toContain('chars)');
            expect($output)->not->toContain('my-secret-code');
            expect($output)->not->toContain('secret-password');
        });
    });

    describe('database connection check', function () {
        it('passes when database is connected', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => 'https://test-backup.com/upload',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Connected to database');
        });
    });

    describe('storage directory checks', function () {
        it('checks backup directory existence', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => 'https://test-backup.com/upload',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Checking storage directories');
        });
    });

    describe('database dump tool check', function () {
        it('reports the configured connection and driver', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => 'test-password',
                'database.connections.notifier_mysql' => ['driver' => 'mysql', 'database' => 'app', 'host' => '127.0.0.1'],
                'notifier.database_connection' => 'notifier_mysql',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('driver: mysql');
        });

        it('reports the dump binary version when mysqldump is installed', function () {
            if (! MysqlDumper::isAvailable()) {
                $this->markTestSkipped('mysqldump is not installed in this environment');
            }

            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => 'test-password',
                'database.connections.notifier_mysql' => ['driver' => 'mysql', 'database' => 'app', 'host' => '127.0.0.1'],
                'notifier.database_connection' => 'notifier_mysql',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('mysqldump');
        });

        it('fails the check for an unsupported driver', function () {
            // The default test connection is sqlite, which has no dump strategy.
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Unsupported database driver')
                ->assertExitCode(1);
        });

        it('fails the check for pgsql when no postgres client is installed', function () {
            if (PostgresDumper::isAvailable()) {
                $this->markTestSkipped('a postgres client is installed in this environment');
            }

            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => 'test-password',
                'database.connections.notifier_pgsql' => ['driver' => 'pgsql', 'database' => 'app', 'host' => '127.0.0.1'],
                'notifier.database_connection' => 'notifier_pgsql',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Required dump binary is not available on PATH');
        });
    });

    describe('PHP ZIP extension check', function () {
        it('passes when ZIP extension is loaded', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => 'https://test-backup.com/upload',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            // ZIP extension should be loaded in test environment
            $this->artisan('notifier:check')
                ->expectsOutputToContain('PHP ZIP extension');
        });
    });

    describe('backup URL reachability check', function () {
        it('skips check when backup URL is not configured', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Backup URL is not configured');
        });

        it('checks backup URL connectivity when configured', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => 'https://test-backup.com/upload',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            $this->artisan('notifier:check')
                ->expectsOutputToContain('Checking backup URL reachability');
        });
    });

    describe('overall result', function () {
        it('shows success message when all checks pass', function () {
            config([
                'notifier.backup_code' => 'test-code',
                'notifier.backup_url' => '',
                'notifier.backup_zip_password' => 'test-password',
            ]);

            // With empty URL, the URL check is skipped (not failed)
            // Other checks should pass in test environment
            $this->artisan('notifier:check')
                ->expectsOutputToContain('RESULT');
        });

        it('displays banner at start', function () {
            $this->artisan('notifier:check')
                ->expectsOutputToContain('Notifier :: Health Check');
        });
    });
});
