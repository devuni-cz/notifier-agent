<?php

declare(strict_types=1);

namespace Devuni\Notifier\Traits;

use Illuminate\Console\Command;

/**
 * Shared CLI report vocabulary for the notifier:* commands.
 *
 * Gives every command one consistent way to render section headers, status
 * lines, aligned detail rows and hints, plus a closing "Summary" recap with a
 * tri-state RESULT badge. Requires the using class to be an
 * {@see Command} that also uses {@see DisplayHelperTrait}
 * (for the themed badge).
 */
trait RendersReportTrait
{
    protected const STATUS_PASS = 'pass';

    protected const STATUS_WARN = 'warn';

    protected const STATUS_FAIL = 'fail';

    /**
     * Outcomes recorded for the closing summary, in run order.
     *
     * @var list<array{label: string, status: self::STATUS_*}>
     */
    protected array $reportResults = [];

    /**
     * Render a section header. The phrase is shown verbatim after "Checking "
     * (keep it lower-case, acronyms preserved); the Title-Case recap label is
     * passed separately to record().
     */
    protected function section(string $phrase): void
    {
        $this->line("<fg=yellow;options=bold>🔍 Checking {$phrase}...</>");
    }

    protected function passLine(string $message): void
    {
        $this->line("   <fg=green>✓</> {$message}");
    }

    protected function warnLine(string $message): void
    {
        $this->line("   <fg=yellow>⚠</> {$message}");
    }

    protected function failLine(string $message): void
    {
        $this->line("   <fg=red>✗</> {$message}");
    }

    protected function infoLine(string $message): void
    {
        $this->line("   <fg=gray>ℹ {$message}</>");
    }

    /**
     * Render an aligned "Label: value" detail row.
     */
    protected function detail(string $label, string $value): void
    {
        $this->line("   <fg=gray>{$label}:</> {$value}");
    }

    protected function hint(string $message): void
    {
        $this->line("   <fg=gray>→ {$message}</>");
    }

    /**
     * Record a step/check outcome for the summary and close its block with a
     * blank line.
     *
     * @param  self::STATUS_*  $status
     */
    protected function record(string $label, string $status): void
    {
        $this->reportResults[] = ['label' => $label, 'status' => $status];

        $this->newLine();
    }

    /**
     * Render the closing "Summary" recap (per-record status list + tallies) and
     * a tri-state RESULT badge. Returns Command::FAILURE when any record failed,
     * otherwise Command::SUCCESS (warnings do not fail the command).
     */
    protected function renderReportSummary(string $okMessage, string $warnMessage, string $failMessage): int
    {
        $passed = $this->countByStatus(self::STATUS_PASS);
        $warnings = $this->countByStatus(self::STATUS_WARN);
        $failures = $this->countByStatus(self::STATUS_FAIL);

        $this->output->writeln('  '.$this->displayBadge(' Summary '));
        $this->newLine();

        $labelWidth = $this->reportResults === []
            ? 0
            : max(array_map(static fn (array $r): int => mb_strlen($r['label']), $this->reportResults));

        foreach ($this->reportResults as $result) {
            $label = mb_str_pad($result['label'], $labelWidth);
            $this->line('   '.$this->statusIcon($result['status'])."  <fg=gray>{$label}</>");
        }

        $this->newLine();
        $this->line(sprintf(
            '   <fg=green>%d passed</> <fg=gray>·</> %s <fg=gray>·</> %s',
            $passed,
            $warnings > 0 ? "<fg=yellow>{$warnings} ".($warnings === 1 ? 'warning' : 'warnings').'</>' : '<fg=gray>0 warnings</>',
            $failures > 0 ? "<fg=red>{$failures} failed</>" : '<fg=gray>0 failed</>',
        ));
        $this->newLine();

        if ($failures > 0) {
            $this->line("<bg=red;fg=white;options=bold> RESULT </> <fg=red>{$failMessage}</>");
            $this->newLine();

            return Command::FAILURE;
        }

        if ($warnings > 0) {
            $this->line("<bg=yellow;fg=black;options=bold> RESULT </> <fg=yellow>{$warnMessage}</>");
            $this->newLine();

            return Command::SUCCESS;
        }

        $this->line("<bg=green;fg=white;options=bold> RESULT </> <fg=green>{$okMessage}</>");
        $this->newLine();

        return Command::SUCCESS;
    }

    /**
     * @param  self::STATUS_*  $current
     * @param  self::STATUS_*  $candidate
     * @return self::STATUS_*
     */
    protected function worst(string $current, string $candidate): string
    {
        $rank = [self::STATUS_PASS => 0, self::STATUS_WARN => 1, self::STATUS_FAIL => 2];

        return $rank[$candidate] > $rank[$current] ? $candidate : $current;
    }

    /**
     * @param  self::STATUS_*  $status
     */
    protected function statusIcon(string $status): string
    {
        return match ($status) {
            self::STATUS_PASS => '<fg=green>✓</>',
            self::STATUS_WARN => '<fg=yellow>⚠</>',
            self::STATUS_FAIL => '<fg=red>✗</>',
        };
    }

    /**
     * @param  self::STATUS_*  $status
     */
    protected function countByStatus(string $status): int
    {
        return count(array_filter($this->reportResults, static fn (array $r): bool => $r['status'] === $status));
    }

    /**
     * Mask a secret for display. We only report presence + length - never any
     * plaintext characters - so a shared secret can't leak into terminal
     * scrollback or CI logs from a diagnostic command.
     */
    protected function maskValue(?string $value): string
    {
        if (empty($value)) {
            return '<fg=red>(empty)</>';
        }

        return '<fg=green>set</> <fg=gray>('.mb_strlen($value).' chars)</>';
    }

    /**
     * Format a byte count as a human-readable size (e.g. "1.21 GB").
     *
     * Uses an exact integer-style divide loop rather than floor(log()), whose
     * float precision can misclassify exact power-of-1024 boundaries on some
     * libm builds (e.g. 1024 rendering as "1024 B" instead of "1 KB").
     */
    protected function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $power = 0;

        while ($value >= 1024 && $power < count($units) - 1) {
            $value /= 1024;
            $power++;
        }

        return round($value, 2).' '.$units[$power];
    }
}
