<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Traits\DisplayHelperTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

final class NotifierInstallCommand extends Command
{
    use DisplayHelperTrait;

    /**
     * Minimum length for the backup ZIP password. It encrypts the entire
     * database + storage archive, so a weak value is crackable offline once
     * an attacker has the archive.
     */
    private const MIN_BACKUP_PASSWORD_LENGTH = 16;

    /**
     * Minimum number of distinct characters - rejects low-entropy values such
     * as "aaaaaaaaaaaaaaaa" or "1234123412341234" that pass a length-only check.
     */
    private const MIN_BACKUP_PASSWORD_UNIQUE_CHARS = 6;

    protected $signature = 'notifier:install {--force : Overwrites existing environment variables}';

    protected $description = 'Configure environment variables for Notifier package';

    /**
     * Validate the strength of a manually-entered backup password. Returns an
     * error message to display, or null when the password is acceptable.
     */
    public static function backupPasswordError(string $password): ?string
    {
        if (mb_strlen($password) < self::MIN_BACKUP_PASSWORD_LENGTH) {
            return 'Backup password must be at least '.self::MIN_BACKUP_PASSWORD_LENGTH.' characters (it encrypts the whole backup).';
        }

        if (count(array_unique(mb_str_split($password))) < self::MIN_BACKUP_PASSWORD_UNIQUE_CHARS) {
            return 'Backup password is too predictable - use a more varied value (or let the installer generate one).';
        }

        return null;
    }

    public function handle()
    {
        if ($this->ifAlreadyInstalled()) {
            error('The Notifier configuration already exists. Use --force to overwrite.');

            return self::FAILURE;
        }

        $this->displayNotifierHeader('Install');

        if ($this->ensureEnvFileExists() === self::FAILURE) {
            return self::FAILURE;
        }

        info('🔧 Please provide the required environment values:');

        $backupCode = text(
            label: 'NOTIFIER_BACKUP_CODE',
            placeholder: 'Enter your backup code',
            required: 'Backup code is required.',
        );

        $backupUrl = text(
            label: 'NOTIFIER_URL',
            placeholder: 'https://your-notifier-server.com',
            required: 'Backup URL is required.',
        );

        if (confirm(label: 'Generate a strong backup password automatically?', default: true)) {
            $backupPassword = bin2hex(random_bytes(24)); // 48 hex chars
            warning('Store this backup password securely - it is required to restore (decrypt) a backup:');
            info($backupPassword);
        } else {
            $backupPassword = password(
                label: 'NOTIFIER_BACKUP_PASSWORD',
                placeholder: 'At least '.self::MIN_BACKUP_PASSWORD_LENGTH.' characters',
                required: 'Backup password is required.',
                validate: fn (string $value): ?string => self::backupPasswordError($value),
            );
        }

        $this->updateEnv([
            'NOTIFIER_BACKUP_CODE' => $backupCode,
            'NOTIFIER_URL' => $backupUrl,
            'NOTIFIER_BACKUP_PASSWORD' => $backupPassword,
        ]);

        info('Notifier environment configuration was saved successfully!');

        return self::SUCCESS;
    }

    private function ensureEnvFileExists(): int
    {
        if (! File::exists(base_path('.env'))) {
            warning('Missing configuration file: .env');
            $this->line('<fg=gray>🔹 This package requires an <fg=green>.env</> file to store environment settings.</>');
            $this->line('<fg=gray>🔹 You can create it from the template: <fg=green>.env.example</>');
            $this->newLine();

            if (confirm('Do you want to create .env from .env.example?', default: true)) {
                File::copy(base_path('.env.example'), base_path('.env'));
                info('.env file has been created.');
                $this->newLine();
            } else {
                error('Installation aborted! .env file is required.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function updateEnv(array $data): void
    {
        $envPath = base_path('.env');
        $envContent = file_get_contents($envPath);

        foreach ($data as $key => $value) {
            $pattern = "/^{$key}=.*$/m";
            $line = $key.'='.$this->formatEnvValue($value);

            if (preg_match($pattern, $envContent)) {
                // Callback keeps the replacement literal - preg_replace()
                // would reinterpret backslashes and $ in the escaped value.
                $envContent = preg_replace_callback($pattern, fn (): string => $line, $envContent);
            } else {
                $envContent .= PHP_EOL.$line;
            }
        }

        file_put_contents($envPath, $envContent);
    }

    /**
     * Quote a value for a dotenv file: always double-quoted, with embedded
     * backslashes and double quotes escaped so the value round-trips intact.
     */
    private function formatEnvValue(string $value): string
    {
        $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return "\"{$escaped}\"";
    }

    private function ifAlreadyInstalled(): bool
    {
        $envPath = base_path('.env');
        if (! File::exists($envPath)) {
            return false;
        }
        $envContent = file_get_contents($envPath);
        $requiredKeys = ['NOTIFIER_BACKUP_CODE', 'NOTIFIER_URL', 'NOTIFIER_BACKUP_PASSWORD'];
        $alreadySet = collect($requiredKeys)->every(function ($key) use ($envContent) {
            if (preg_match("/^{$key}=(.*)$/m", $envContent, $matches)) {
                $value = mb_trim($matches[1], '"');

                return $value !== '';
            }

            return false;
        });

        return $alreadySet && ! $this->option('force');
    }
}
