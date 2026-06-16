<?php

declare(strict_types=1);

namespace Devuni\Notifier\Commands;

use Devuni\Notifier\Services\HeartbeatService;
use Devuni\Notifier\Traits\DisplayHelperTrait;
use Illuminate\Console\Command;
use Throwable;

final class NotifierHeartbeatCommand extends Command
{
    use DisplayHelperTrait;

    protected $signature = 'notifier:heartbeat';

    protected $description = 'Send agent heartbeat + identity manifest to the control plane';

    public function handle(HeartbeatService $service): int
    {
        $this->displayNotifierHeader('Heartbeat');

        if (! config('notifier.features.heartbeat', true)) {
            $this->line('<fg=gray>ℹ Heartbeat feature is disabled (NOTIFIER_HEARTBEAT_ENABLED=false) - nothing to send.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        try {
            $manifest = $service->gatherManifest();
            $service->sendHeartbeat($manifest);
        } catch (Throwable $e) {
            $this->line('<bg=red;fg=white;options=bold> FAILED </> <fg=red>'.$e->getMessage().'</>');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line('<fg=green>✅ Heartbeat sent to the control plane.</>');
        $this->newLine();

        return self::SUCCESS;
    }
}
