<?php

declare(strict_types=1);

namespace Devuni\Notifier\Middleware;

use Closure;
use Devuni\Notifier\Services\NotifierConfigService;
use Devuni\Notifier\Services\NotifierLoggerService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class VerifyNotifierTokenMiddleware
{
    public function __construct(
        private readonly NotifierConfigService $configService,
        private readonly NotifierLoggerService $notifierLogger,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $missingVariables = $this->configService->checkEnvironment();

        if (! empty($missingVariables)) {
            $this->notifierLogger->get()->error('Backup request rejected: server configuration incomplete.', [
                'missing_variables' => $missingVariables,
            ]);

            return $this->deny();
        }

        $expectedToken = config('notifier.backup_code');

        $providedToken = $request->header('X-Notifier-Token');

        if (empty($providedToken)) {
            $this->notifierLogger->get()->warning('Backup request rejected: missing authentication token.', [
                'ip' => $request->ip(),
            ]);

            return $this->deny();
        }

        if (! hash_equals($expectedToken, $providedToken)) {
            $this->notifierLogger->get()->warning('Backup request rejected: invalid authentication token.', [
                'ip' => $request->ip(),
            ]);

            return $this->deny();
        }

        return $next($request);
    }

    /**
     * Unauthenticated callers always receive the same generic response,
     * regardless of whether the token is missing, wrong, or the server-side
     * environment is misconfigured - so the endpoint leaks nothing about its
     * configuration before authentication. The real reason is logged above.
     */
    private function deny(): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Invalid authentication token.',
        ], 403);
    }
}
