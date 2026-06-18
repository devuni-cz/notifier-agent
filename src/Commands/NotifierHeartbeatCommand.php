<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Illuminate\Console\Command;
use Throwable;

final class NotifierHeartbeatCommand extends Command
{
    use DisplayHelperTrait;
    use RendersReportTrait;

    protected $signature = 'notifier:heartbeat';

    protected $description = 'Report agent status and identity to the Notifier server';

    public function handle(HeartbeatService $service): int
    {
        $this->displayNotifierHeader('Heartbeat');

        if (! config('notifier.features.heartbeat', true)) {
            $this->infoLine('Heartbeat feature is disabled (NOTIFIER_HEARTBEAT_ENABLED=false) - nothing to send.');
            $this->newLine();

            return self::SUCCESS;
        }

        try {
            $manifest = $service->gatherManifest();
            $service->sendHeartbeat($manifest);

            $this->passLine('Heartbeat delivered to the Notifier server.');
            $this->renderManifest($manifest);
            $this->record('Heartbeat', self::STATUS_PASS);
        } catch (Throwable $e) {
            $this->failLine('Heartbeat failed: '.$e->getMessage());
            $this->record('Heartbeat', self::STATUS_FAIL);
        }

        return $this->renderReportSummary(
            'Heartbeat delivered.',
            'Heartbeat delivered with warnings.',
            'Heartbeat could not be delivered. See the error above.',
        );
    }

    /**
     * Show the operator what was actually reported to the control plane.
     *
     * @param  array<string, mixed>  $manifest
     */
    private function renderManifest(array $manifest): void
    {
        $features = collect($manifest['enabled_features'] ?? [])
            ->filter()
            ->keys()
            ->implode(', ');

        $this->detail('Agent version', (string) ($manifest['agent_version'] ?? 'unknown'));
        $this->detail('Queue', (string) ($manifest['queue_connection'] ?? 'unknown'));
        $this->detail('Enabled features', $features !== '' ? $features : 'none');
        $this->detail('Last database backup', (string) ($manifest['last_database_backup_at'] ?? 'never'));
        $this->detail('Last storage backup', (string) ($manifest['last_storage_backup_at'] ?? 'never'));

        $free = $manifest['disk_free_bytes'] ?? null;
        $total = $manifest['disk_total_bytes'] ?? null;
        if (is_int($free) && is_int($total) && $total > 0) {
            $this->detail('Disk free', $this->humanBytes($free).' / '.$this->humanBytes($total));
        }
    }
}
