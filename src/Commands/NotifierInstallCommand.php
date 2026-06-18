<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

final class NotifierInstallCommand extends Command
{
    use DisplayHelperTrait;
    use RendersReportTrait;

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

    protected $description = 'Configure the Notifier agent credentials in your .env file';

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

    public function handle(): int
    {
        $this->displayNotifierHeader('Install');

        if ($this->ifAlreadyInstalled()) {
            $this->failLine('The Notifier configuration already exists. Use --force to overwrite.');
            $this->newLine();

            return self::FAILURE;
        }

        if ($this->ensureEnvFileExists() === self::FAILURE) {
            return self::FAILURE;
        }

        $this->line('<fg=yellow;options=bold>Please provide the required environment values:</>');
        $this->newLine();

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
            $this->warnLine('Store this backup password securely - it is required to restore (decrypt) a backup:');
            $this->line("   <fg=cyan>{$backupPassword}</>");
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

        $this->newLine();
        $this->passLine('Configuration saved to .env');
        $this->detail('NOTIFIER_BACKUP_CODE', $this->maskValue($backupCode));
        $this->detail('NOTIFIER_URL', '<fg=cyan>'.$backupUrl.'</>');
        $this->detail('NOTIFIER_BACKUP_PASSWORD', $this->maskValue($backupPassword));
        $this->hint('Next: run <fg=cyan>php artisan notifier:check</> to verify the configuration.');
        $this->record('Configuration', self::STATUS_PASS);

        return $this->renderReportSummary(
            'Notifier agent configured. Run notifier:check to verify.',
            '',
            'Configuration could not be saved.',
        );
    }

    private function ensureEnvFileExists(): int
    {
        if (File::exists(base_path('.env'))) {
            return self::SUCCESS;
        }

        $this->warnLine('Missing configuration file: .env');
        $this->infoLine('This package needs an .env file to store environment settings.');
        $this->hint('It can be created from the template .env.example.');
        $this->newLine();

        if (! confirm('Do you want to create .env from .env.example?', default: true)) {
            $this->failLine('Installation aborted! .env file is required.');
            $this->newLine();

            return self::FAILURE;
        }

        File::copy(base_path('.env.example'), base_path('.env'));
        $this->passLine('.env file has been created.');
        $this->newLine();

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
