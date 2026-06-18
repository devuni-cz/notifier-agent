<?php

declare(strict_types=1);

namespace Devuni\Notifier\Traits;

use Composer\InstalledVersions;
use Devuni\Notifier\Enums\ThemeEnum;
use OutOfBoundsException;

use function Laravel\Prompts\note;

trait DisplayHelperTrait
{
    protected ?ThemeEnum $theme = null;

    protected function initTheme(?ThemeEnum $theme = null): void
    {
        $this->theme = $theme ?? ThemeEnum::random();
    }

    protected function displayNotifierHeader(string $featureName, ?ThemeEnum $theme = null): void
    {
        $this->initTheme($theme);

        $this->displayGradientLogo();
        $this->displayTagline($featureName);
        $this->displayPackageInfo();
    }

    protected function displayGradientLogo(): void
    {
        // Pure decoration - skip it on non-interactive output (scheduler, cron,
        // redirected logs) so we never dump ASCII art into a log file. The
        // tagline below still carries the identity in plain text.
        if (! $this->output->isDecorated()) {
            return;
        }

        $lines = [
            '  ███╗   ██╗ ██████╗ ████████╗██╗███████╗██╗███████╗██████╗ ',
            '  ████╗  ██║██╔═══██╗╚══██╔══╝██║██╔════╝██║██╔════╝██╔══██╗',
            '  ██╔██╗ ██║██║   ██║   ██║   ██║█████╗  ██║█████╗  ██████╔╝',
            '  ██║╚██╗██║██║   ██║   ██║   ██║██╔══╝  ██║██╔══╝  ██╔══██╗',
            '  ██║ ╚████║╚██████╔╝   ██║   ██║██║     ██║███████╗██║  ██║',
            '  ╚═╝  ╚═══╝ ╚═════╝    ╚═╝   ╚═╝╚═╝     ╚═╝╚══════╝╚═╝  ╚═╝',
        ];

        $gradient = $this->theme->gradient();

        $this->newLine();

        foreach ($lines as $index => $line) {
            $this->output->writeln($this->ansi256Fg($gradient[$index], $line));
        }

        $this->newLine();
    }

    protected function displayTagline(string $featureName): void
    {
        $tagline = " ✦ Notifier :: {$featureName} ✦ ";
        $this->output->writeln('  '.$this->displayBadge($tagline));
    }

    protected function displayPackageInfo(): void
    {
        $version = $this->getCurrentVersion();

        note(" devuni/notifier-agent {$this->displayBadge(" {$version} ")}");
    }

    protected function ansi256Fg(int $color, string $text): string
    {
        if (! $this->output->isDecorated()) {
            return $text;
        }

        return "\e[38;5;{$color}m{$text}\e[0m";
    }

    protected function displayBadge(string $text): string
    {
        if (! $this->output->isDecorated()) {
            return $text;
        }

        $primary = $this->theme?->primary() ?? 39;

        return "\e[48;5;{$primary}m\e[30m\e[1m{$text}\e[0m";
    }

    private function getCurrentVersion(): string
    {
        try {
            return InstalledVersions::getPrettyVersion('devuni/notifier-agent') ?? 'custom';
        } catch (OutOfBoundsException $e) {
            return 'unknown';
        }
    }
}
