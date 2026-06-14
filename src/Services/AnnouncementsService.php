<?php

declare(strict_types=1);

namespace Devuni\Notifier\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Pulls this site's active maintenance/announcement notices from the central server.
 *
 * The request is per-repository: it reuses the configured backup URL
 * (`{NOTIFIER_URL}/announcements`, e.g. `.../api/v1/repositories/52740614/announcements`) and the
 * same `X-Notifier-Token`, so the server already knows which site is asking and
 * returns only that site's announcements - no other repositories are ever disclosed.
 *
 * Responses are cached so the consumer's dashboard never makes a blocking HTTP
 * request on every page load, and failures are swallowed (returning an empty
 * list) so a down/slow announcement server can never break the dashboard.
 */
final class AnnouncementsService
{
    public function __construct(
        private readonly NotifierApiClient $api,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    /**
     * Active announcements for this site. Always returns a list and never throws.
     *
     * @return list<array<string, mixed>>
     */
    public function activeAnnouncements(): array
    {
        if (! config('notifier.features.announcements', false)) {
            return [];
        }

        $baseUrl = config('notifier.backup_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            return [];
        }

        $cacheKey = $this->cacheKey($baseUrl);
        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            /** @var list<array<string, mixed>> $cached */
            return $cached;
        }

        return $this->fetchAndCache($cacheKey);
    }

    /**
     * For non-Filament (custom SPA) hosts: the host renders these into its own
     * element(s) keyed by 'target'. Returns the active announcements whose
     * `dashboard_type` is "custom", optionally narrowed to a single target
     * element id. Always returns a list and never throws (same robustness
     * contract as activeAnnouncements()).
     *
     * @return list<array<string, mixed>>
     */
    public function customAnnouncements(?string $target = null): array
    {
        $custom = array_values(array_filter(
            $this->activeAnnouncements(),
            static fn (array $announcement): bool => ($announcement['dashboard_type'] ?? null) === 'custom',
        ));

        if ($target === null) {
            return $custom;
        }

        return array_values(array_filter(
            $custom,
            static fn (array $announcement): bool => ($announcement['target'] ?? null) === $target,
        ));
    }

    /**
     * The repository id this site reports as, parsed from the configured
     * NOTIFIER_URL (`.../repositories/{id}`). Null if it can't be determined.
     */
    public function repositoryId(): ?string
    {
        $baseUrl = config('notifier.backup_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            return null;
        }

        $path = parse_url($baseUrl, PHP_URL_PATH);

        if (is_string($path) && preg_match('#/repositories/([^/]+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchAndCache(string $cacheKey): array
    {
        $timeout = (int) config('notifier.announcements.timeout', 5);

        try {
            // All transport invariants (HTTPS-only, token, no-redirect) live in the
            // shared client, so this pull can never leak the secret over cleartext.
            $response = $this->api->get('/announcements', $timeout);
        } catch (Throwable $e) {
            return $this->cacheFailure($cacheKey, $e->getMessage());
        }

        if (! $response->successful()) {
            return $this->cacheFailure($cacheKey, 'HTTP '.$response->status().' - '.$this->api->formatError($response));
        }

        $announcements = $response->json('announcements');

        $announcements = is_array($announcements)
            ? array_values(array_map($this->normalizePlacement(...), $announcements))
            : [];

        Cache::put($cacheKey, $announcements, (int) config('notifier.announcements.cache_ttl', 900));

        /** @var list<array<string, mixed>> $announcements */
        return $announcements;
    }

    /**
     * Ensure each wire item exposes the placement keys with sane defaults:
     * `dashboard_type` falls back to "filament" (so older servers and the common
     * case route to the Filament banner) and `target` falls back to null (use the
     * agent default). All other server-sent keys pass through untouched.
     *
     * @param  array<string, mixed>  $announcement
     * @return array<string, mixed>
     */
    private function normalizePlacement(array $announcement): array
    {
        $dashboardType = mb_trim((string) ($announcement['dashboard_type'] ?? ''));
        $target = mb_trim((string) ($announcement['target'] ?? ''));

        $announcement['dashboard_type'] = $dashboardType !== '' ? $dashboardType : 'filament';
        $announcement['target'] = $target !== '' ? $target : null;

        return $announcement;
    }

    /**
     * Log the failure and briefly negative-cache an empty result, so a down or
     * slow server doesn't make every dashboard page load pay the HTTP timeout.
     *
     * @return list<array<string, mixed>>
     */
    private function cacheFailure(string $cacheKey, string $reason): array
    {
        $this->notifierLogger->get()->warning('⚠️ failed to fetch notifier announcements', [
            'reason' => $reason,
        ]);

        Cache::put($cacheKey, [], (int) config('notifier.announcements.failure_cache_ttl', 60));

        return [];
    }

    private function cacheKey(string $baseUrl): string
    {
        return 'notifier.announcements.'.($this->repositoryId() ?? md5($baseUrl));
    }
}
