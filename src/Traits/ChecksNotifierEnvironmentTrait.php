<?php

declare(strict_types=1);

namespace Devuni\Notifier\Traits;

use Devuni\Notifier\Services\NotifierConfigService;
use Illuminate\Console\Command;

/**
 * Guard a command on the required Notifier environment variables.
 *
 * Renders the missing-variable report through the shared vocabulary so the
 * backup commands' misconfiguration path looks identical to notifier:check
 * (section header, status line, bullets, hint) and closes with the same
 * Summary + RESULT footer. The using class must be a {@see Command} that also
 * uses {@see RendersReportTrait} and {@see DisplayHelperTrait}.
 *
 * @mixin Command
 * @mixin RendersReportTrait
 */
trait ChecksNotifierEnvironmentTrait
{
    private function checkMissingVariables(NotifierConfigService $configService): int
    {
        $missingVariables = $configService->checkEnvironment();

        if ($missingVariables === []) {
            return static::SUCCESS;
        }

        $this->section('environment variables');
        $this->failLine('Missing environment variables:');

        foreach ($missingVariables as $variable) {
            $this->line("        <fg=red>•</> {$variable}");
        }

        $this->hint('Run: php artisan notifier:install');
        $this->record('Environment variables', self::STATUS_FAIL);

        return $this->renderReportSummary(
            'Environment is configured.',
            '',
            'Some environment variables are missing. Run notifier:install to set them.',
        );
    }
}
