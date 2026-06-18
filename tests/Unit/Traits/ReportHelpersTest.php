<?php

declare(strict_types=1);

use Devuni\Notifier\Traits\DisplayHelperTrait;
use Devuni\Notifier\Traits\RendersReportTrait;
use Devuni\Notifier\Traits\RunsBackupTrait;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Compose the report traits exactly as the notifier commands do, exposing the
 * protected/private helpers so they can be unit-tested in isolation. Returns
 * [$command, $buffer] so tests can both call helpers and read rendered output.
 *
 * @return array{0: Command, 1: BufferedOutput}
 */
function reportingHarness(bool $decorated = false): array
{
    $buffer = new BufferedOutput;
    $buffer->setDecorated($decorated);

    $command = new class extends Command
    {
        use DisplayHelperTrait;
        use RendersReportTrait;
        use RunsBackupTrait;

        public function bytes(int $b): string
        {
            return $this->humanBytes($b);
        }

        public function mask(?string $v): string
        {
            return $this->maskValue($v);
        }

        public function duration(float $s): string
        {
            return $this->humanDuration($s);
        }

        public function badge(string $t): string
        {
            return $this->displayBadge($t);
        }

        public function fg(int $c, string $t): string
        {
            return $this->ansi256Fg($c, $t);
        }

        public function pushRecord(string $label, string $status): void
        {
            $this->record($label, $status);
        }

        public function summary(string $ok, string $warn, string $fail): int
        {
            return $this->renderReportSummary($ok, $warn, $fail);
        }
    };

    $command->setOutput(new OutputStyle(new ArrayInput([]), $buffer));

    return [$command, $buffer];
}

describe('humanBytes', function () {
    it('formats byte counts into human-readable units', function () {
        [$cmd] = reportingHarness();

        expect($cmd->bytes(0))->toBe('0 B')
            ->and($cmd->bytes(512))->toBe('512 B')
            ->and($cmd->bytes(1024))->toBe('1 KB')
            ->and($cmd->bytes(1536))->toBe('1.5 KB')
            ->and($cmd->bytes(1024 ** 2))->toBe('1 MB')
            ->and($cmd->bytes(5 * 1024 ** 3))->toBe('5 GB');
    });
});

describe('maskValue', function () {
    it('reports presence + length and never the plaintext', function () {
        [$cmd] = reportingHarness();

        expect($cmd->mask('super-secret'))->toContain('set')->toContain('12 chars')
            ->and($cmd->mask('super-secret'))->not->toContain('super-secret');
    });

    it('reports an empty marker for null and empty values', function () {
        [$cmd] = reportingHarness();

        expect($cmd->mask(null))->toContain('(empty)')
            ->and($cmd->mask(''))->toContain('(empty)');
    });
});

describe('humanDuration', function () {
    it('never renders an impossible 60-second component on the minute boundary', function () {
        [$cmd] = reportingHarness();

        expect($cmd->duration(119.6))->toBe('2 m 0 s')
            ->and($cmd->duration(3599.7))->toBe('60 m 0 s')
            ->and($cmd->duration(59.96))->toBe('1 m 0 s');
    });

    it('keeps sub-minute durations as one-decimal seconds', function () {
        [$cmd] = reportingHarness();

        expect($cmd->duration(5.55))->toBe('5.6 s')
            ->and($cmd->duration(0.04))->toBe('0 s');
    });
});

describe('isDecorated ANSI gating', function () {
    it('returns plain text when the output is not decorated', function () {
        [$cmd] = reportingHarness(decorated: false);

        expect($cmd->badge(' X '))->toBe(' X ')
            ->and($cmd->fg(45, 'hi'))->toBe('hi');
    });

    it('emits raw ANSI escapes when the output is decorated', function () {
        [$cmd] = reportingHarness(decorated: true);

        expect($cmd->badge(' X '))->toContain("\e[48;5;")
            ->and($cmd->fg(45, 'hi'))->toContain("\e[38;5;45m");
    });
});

describe('renderReportSummary', function () {
    it('returns SUCCESS and the warn message when only warnings were recorded', function () {
        [$cmd, $buffer] = reportingHarness();

        $cmd->pushRecord('Something', 'warn');
        $exit = $cmd->summary('all good', 'heads up - a warning', 'it failed');

        expect($exit)->toBe(Command::SUCCESS);
        expect($buffer->fetch())
            ->toContain('heads up - a warning')
            ->toContain('1 warning')
            ->toContain('RESULT');
    });

    it('returns FAILURE and the fail message when a failure was recorded', function () {
        [$cmd, $buffer] = reportingHarness();

        $cmd->pushRecord('Something', 'fail');
        $exit = $cmd->summary('all good', 'a warning', 'it failed badly');

        expect($exit)->toBe(Command::FAILURE);
        expect($buffer->fetch())
            ->toContain('it failed badly')
            ->toContain('1 failed');
    });
});
