<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Cache;
use OutOfBoundsException;
use RuntimeException;
use Throwable;

/**
 * Pushes this site's identity + liveness manifest to the central server.
 *
 * The heartbeat reuses the configured backup URL (`{NOTIFIER_URL}/heartbeat`,
 * e.g. `.../api/v1/repositories/52740614/heartbeat`) and the same
 * `X-Notifier-Token`, so the server already knows which site is reporting and
 * stamps its own `last_heartbeat_at` on receipt — the agent never sends that.
 *
 * Unlike the announcements PULL (which swallows failures so a down server can't
 * break the dashboard), the heartbeat is a PUSH: gathering the manifest is always
 * safe and never throws, but sending it LOGS + THROWS on failure so the operator
 * or host scheduler sees that the control plane stopped hearing from this site.
 */
final class HeartbeatService
{
    /**
     * Cache key holding the ISO8601 timestamp of the last successful database backup.
     */
    public const LAST_DATABASE_BACKUP_KEY = 'notifier.last_database_backup_at';

    /**
     * Cache key holding the ISO8601 timestamp of the last successful storage backup.
     */
    public const LAST_STORAGE_BACKUP_KEY = 'notifier.last_storage_backup_at';

    public function __construct(
        private readonly NotifierApiClient $api,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    /**
     * Build the wire-contract manifest the server's StoreHeartbeatRequest accepts.
     *
     * This is always instant and never throws — every value is read defensively so
     * a heartbeat can report even from a partially misconfigured host.
     *
     * @return array<string, mixed>
     */
    public function gatherManifest(): array
    {
        [$diskFree, $diskTotal] = $this->diskBytes();

        return [
            'agent_version' => $this->agentVersion(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'queue_connection' => config('notifier.queue_connection', config('queue.default')),
            'enabled_features' => [
                'announcements' => (bool) config('notifier.features.announcements', true),
                'backups' => (bool) config('notifier.features.backups', true),
                'heartbeat' => (bool) config('notifier.features.heartbeat', true),
            ],
            'disk_free_bytes' => $diskFree,
            'disk_total_bytes' => $diskTotal,
            'last_database_backup_at' => $this->lastBackupAt(self::LAST_DATABASE_BACKUP_KEY),
            'last_storage_backup_at' => $this->lastBackupAt(self::LAST_STORAGE_BACKUP_KEY),
            'reported_at' => now()->toIso8601String(),
        ];
    }

    /**
     * POST the manifest to the control plane. No-ops when the feature is disabled.
     *
     * On any non-2xx response or transport error this logs the failure and throws
     * a RuntimeException (push semantics: the caller/host must know the control
     * plane is no longer hearing from this site).
     *
     * @param  array<string, mixed>  $manifest
     *
     * @throws RuntimeException When the heartbeat could not be delivered.
     */
    public function sendHeartbeat(array $manifest): void
    {
        if (! config('notifier.features.heartbeat', true)) {
            $this->notifierLogger->get()->debug('🫥 notifier heartbeat skipped (feature disabled)');

            return;
        }

        try {
            // All transport invariants (HTTPS-only, token, no-redirect) live in the
            // shared client, so this push can never leak the secret over cleartext.
            $response = $this->api->post('/heartbeat', $manifest, 30);
        } catch (Throwable $e) {
            $this->notifierLogger->get()->error('❌ failed to send notifier heartbeat', [
                'reason' => $e->getMessage(),
            ]);

            throw new RuntimeException('Failed to send notifier heartbeat: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $reason = 'HTTP '.$response->status().' - '.$this->api->formatError($response);

            $this->notifierLogger->get()->error('❌ notifier heartbeat rejected by server', [
                'reason' => $reason,
            ]);

            throw new RuntimeException('Notifier heartbeat rejected: '.$reason);
        }

        $this->notifierLogger->get()->info('✅ notifier heartbeat sent', [
            'agent_version' => $manifest['agent_version'] ?? null,
        ]);
    }

    /**
     * The installed package version, mirroring DisplayHelperTrait::getCurrentVersion().
     */
    private function agentVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('devuni/notifier-agent') ?? 'custom';
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }

    /**
     * Free + total bytes on the storage volume, or null when unavailable
     * (e.g. open_basedir restrictions). Never throws.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function diskBytes(): array
    {
        $path = storage_path();

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        return [
            is_numeric($free) ? (int) $free : null,
            is_numeric($total) ? (int) $total : null,
        ];
    }

    /**
     * The ISO8601 timestamp recorded by a backup command, or null if never run.
     */
    private function lastBackupAt(string $cacheKey): ?string
    {
        $value = Cache::get($cacheKey);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
