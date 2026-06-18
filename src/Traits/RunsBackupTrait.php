<?php

declare(strict_types=1);

namespace Devuni\Notifier\Traits;

use Closure;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Shared create -> upload -> record flow for the backup commands.
 *
 * Keeps the database and storage commands as thin wrappers that only supply
 * their service callbacks: the orchestration, error handling, "nothing to back
 * up" skip, success timestamp and reporting live here once. Requires the using
 * class to also use {@see RendersReportTrait} and {@see DisplayHelperTrait} and
 * to be an {@see \Illuminate\Console\Command}.
 */
trait RunsBackupTrait
{
    /**
     * Run one backup end to end and render its report.
     *
     * @param  Closure(): string  $create  Builds the archive; returns its path, or '' when there is nothing to back up.
     * @param  Closure(string): void  $send  Uploads the archive at the given path (and cleans it up).
     * @param  string  $cacheKey  Heartbeat key to stamp with the success time - written ONLY on a real upload.
     */
    protected function runBackup(string $label, Closure $create, Closure $send, string $cacheKey): int
    {
        $startedAt = microtime(true);

        try {
            $this->infoLine('Creating backup archive…');
            $path = $create();

            if ($path === '') {
                $this->warnLine('Nothing to back up — the source is empty, upload skipped.');
                $this->record($label, self::STATUS_WARN);

                return $this->renderBackupSummary($label);
            }

            $bytes = filesize($path);
            $this->passLine('Backup archive created'.($bytes !== false ? " <fg=gray>(<fg=cyan>{$this->humanBytes($bytes)}</>)</>" : ''));

            $this->infoLine('Uploading to the Notifier server…');
            $send($path);

            // Stamp the success time only after a real upload, so the heartbeat
            // manifest never reports a backup that was skipped or failed.
            Cache::forever($cacheKey, now()->toIso8601String());

            $this->passLine('Backup uploaded successfully');
            $this->detail('Duration', $this->humanDuration(microtime(true) - $startedAt));
            $this->record($label, self::STATUS_PASS);
        } catch (Throwable $e) {
            $this->failLine('Backup failed: '.$e->getMessage());
            $this->record($label, self::STATUS_FAIL);
        }

        return $this->renderBackupSummary($label);
    }

    private function renderBackupSummary(string $label): int
    {
        return $this->renderReportSummary(
            "{$label} completed.",
            "{$label} skipped — nothing to back up.",
            "{$label} failed. See the error above.",
        );
    }

    private function humanDuration(float $seconds): string
    {
        // Round to whole seconds once, then split - rounding the seconds
        // component independently could otherwise render an impossible
        // "1 m 60 s" on the minute boundary.
        $total = (int) round($seconds);

        if ($total < 60) {
            return round($seconds, 1).' s';
        }

        return intdiv($total, 60).' m '.($total % 60).' s';
    }
}
