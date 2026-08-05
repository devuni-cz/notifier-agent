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

    /**
     * Reject a restore token that is actually one of the other credentials.
     * The restore token is issued separately on the control plane; pasting the
     * backup code here would leave restore silently broken (the server refuses
     * it) behind a green notifier:check.
     */
    public static function restoreTokenError(string $value, string $backupCode, string $backupPassword): ?string
    {
        if ($value === $backupCode) {
            return 'That is the backup code - the restore token is a separate credential issued on the control plane.';
        }

        if ($value === $backupPassword) {
            return 'That is the backup password - the restore token is a separate credential issued on the control plane.';
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

        // Prefill current values so a --force re-run (e.g. to add the optional
        // credentials below) is an Enter-through, not a retype-everything that
        // invites typos in the backup code.
        $currentBackupCode = $this->currentEnvValue('NOTIFIER_BACKUP_CODE');
        $currentBackupUrl = $this->currentEnvValue('NOTIFIER_URL');

        $backupCode = text(
            label: 'NOTIFIER_BACKUP_CODE',
            placeholder: 'Enter your backup code',
            default: $currentBackupCode ?? '',
            required: 'Backup code is required.',
            hint: $currentBackupCode !== null ? 'Press Enter to keep the current value.' : '',
        );

        $backupUrl = text(
            label: 'NOTIFIER_URL',
            placeholder: 'https://your-notifier-server.com',
            default: $currentBackupUrl ?? '',
            required: 'Backup URL is required.',
            hint: $currentBackupUrl !== null ? 'Press Enter to keep the current value.' : '',
        );

        // Never rotate an existing backup password by accident: every archive
        // already uploaded is encrypted with it, so a casual Enter through the
        // generate prompt would leave those archives undecryptable if the old
        // value is not stored elsewhere.
        $currentPassword = $this->currentEnvValue('NOTIFIER_BACKUP_PASSWORD');
        $backupPassword = null;

        if ($currentPassword !== null && confirm(
            label: 'Keep the existing backup password?',
            default: true,
            hint: 'Already-uploaded archives are encrypted with it - rotating does not re-encrypt them.',
        )) {
            $backupPassword = $currentPassword;
        }

        if ($backupPassword === null) {
            if ($currentPassword !== null) {
                $this->warnLine('Keep the OLD backup password stored somewhere safe - archives uploaded before this change can only be decrypted with it.');
            }

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
        }

        // Optional split credentials. Both default to "skip" so the classic
        // three-value install keeps working unchanged - and neither may be
        // enabled blindly: the restore token is issued by the control plane,
        // and a dedicated trigger secret disables the backup-code fallback for
        // the inbound trigger the moment it is set here.
        $optional = [];

        if (confirm(
            label: 'Configure a restore token now?',
            default: false,
            hint: 'Issued per repository on the control plane. Without it the notifier:database-restore / notifier:storage-restore commands are disabled.',
        )) {
            // password() (not text()): the pasted token must not stay echoed in
            // the terminal scrollback / SSH session recording - it is the one
            // credential that can download this site's data.
            $optional['NOTIFIER_RESTORE_TOKEN'] = password(
                label: 'NOTIFIER_RESTORE_TOKEN',
                placeholder: 'Paste the restore token issued by the control plane',
                required: 'Restore token is required when you choose to configure it.',
                validate: fn (string $value): ?string => self::restoreTokenError($value, $backupCode, $backupPassword),
            );
        }

        if (confirm(
            label: 'Generate a dedicated inbound trigger secret?',
            default: false,
            hint: 'Only opt in if you will configure the SAME value on the control plane - once set here, the backup code stops working for the inbound trigger.',
        )) {
            $optional['NOTIFIER_TRIGGER_SECRET'] = bin2hex(random_bytes(24)); // 48 hex chars
            $this->warnLine('Configure this trigger secret for the repository on the control plane too - remote triggers fail until both sides match:');
            $this->line('   <fg=cyan>'.$optional['NOTIFIER_TRIGGER_SECRET'].'</>');
        }

        $this->updateEnv([
            'NOTIFIER_BACKUP_CODE' => $backupCode,
            'NOTIFIER_URL' => $backupUrl,
            'NOTIFIER_BACKUP_PASSWORD' => $backupPassword,
            ...$optional,
        ]);

        $this->newLine();
        $this->passLine('Configuration saved to .env');
        $this->detail('NOTIFIER_BACKUP_CODE', $this->maskValue($backupCode));
        $this->detail('NOTIFIER_URL', '<fg=cyan>'.$backupUrl.'</>');
        $this->detail('NOTIFIER_BACKUP_PASSWORD', $this->maskValue($backupPassword));

        if (isset($optional['NOTIFIER_RESTORE_TOKEN'])) {
            $this->detail('NOTIFIER_RESTORE_TOKEN', $this->maskValue($optional['NOTIFIER_RESTORE_TOKEN']));
        } else {
            $this->hint('Restore stays disabled until NOTIFIER_RESTORE_TOKEN is set (issue one on the control plane).');
        }

        if (isset($optional['NOTIFIER_TRIGGER_SECRET'])) {
            $this->detail('NOTIFIER_TRIGGER_SECRET', $this->maskValue($optional['NOTIFIER_TRIGGER_SECRET']));
        }

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

    /**
     * The current value of an .env key, unescaped back through the inverse of
     * {@see formatEnvValue()}, or null when the key is absent or empty.
     */
    private function currentEnvValue(string $key): ?string
    {
        $envPath = base_path('.env');

        if (! File::exists($envPath)) {
            return null;
        }

        $envContent = file_get_contents($envPath);

        if (preg_match("/^{$key}=(.*)$/m", $envContent, $matches) !== 1) {
            return null;
        }

        $raw = mb_trim($matches[1]);

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"') && mb_strlen($raw) >= 2) {
            // Single pass so an unescaped result cannot be re-interpreted.
            $raw = preg_replace_callback('/\\\\(["\\\\])/', fn (array $m): string => $m[1], mb_substr($raw, 1, -1));
        }

        return ($raw === null || $raw === '') ? null : $raw;
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
