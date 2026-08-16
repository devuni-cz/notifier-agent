<?php

declare(strict_types=1);

/**
 * Export this package as a vendored copy into a legacy client project.
 *
 * The composer package targets PHP 8.4+ / Laravel 12-13 and that is not going to
 * change. Older client sites therefore carry a copy of the source inside their
 * own repository (`packages/devuni/notifier-agent`), loaded through PSR-4 rather
 * than composer. Those copies were produced by hand once, in July 2026, and have
 * been stuck at 1.6.2 ever since - invisible to `composer audit`, impossible to
 * refresh without redoing the whole transformation.
 *
 * This script makes the copy a build artifact instead: run it again after every
 * release and the legacy sites move with upstream.
 *
 * Usage:
 *   php scripts/vendor-export.php --to=E:/projects/olivy-os.cz --php=8.5 --laravel=8
 *   php scripts/vendor-export.php --to=... --php=8.1 --laravel=9 --dry-run
 *
 * Arguments:
 *   --to        Project root of the target site (the copy lands in
 *               <to>/packages/devuni/notifier-agent).
 *   --php       Lowest PHP version the target runs, e.g. 8.1 or 8.5.
 *   --laravel   Major Laravel version of the target, e.g. 8 or 9.
 *   --dry-run   Report what would happen, write nothing.
 *
 * Every transformation below mirrors one that the hand-made July copy performed;
 * the difference is that here it is reproducible and self-documenting.
 */
final class VendorExport
{
    /** Files that never belong in a vendored copy. */
    private const SKIP_FILES = [
        // Needs laravel/prompts, which requires symfony/console ^6 and cannot be
        // a dependency of a copy that has no composer.json of its own.
        'src/Commands/NotifierInstallCommand.php',
    ];

    /** @var list<string> */
    private array $applied = [];

    /** @var list<string> */
    private array $warnings = [];

    public function __construct(
        private string $root,
        private string $target,
        private string $phpVersion,
        private int $laravelMajor,
        private bool $dryRun,
        private string $version,
    ) {}

    public static function fromArgv(array $argv): self
    {
        $args = [];
        foreach (array_slice($argv, 1) as $arg) {
            if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) !== 1) {
                self::fail("Unrecognised argument: {$arg}");
            }
            $args[$m[1]] = $m[2] ?? '1';
        }

        foreach (['to', 'php', 'laravel'] as $required) {
            if (! isset($args[$required])) {
                self::fail("Missing required --{$required}. See the header of this file for usage.");
            }
        }

        $root = dirname(__DIR__);
        $to = mb_rtrim(str_replace('\\', '/', $args['to']), '/');

        if (! is_dir($to)) {
            self::fail("Target project root does not exist: {$to}");
        }

        if (! is_file($to.'/composer.json')) {
            self::fail("No composer.json in {$to} - that does not look like a project root.");
        }

        $php = $args['php'];
        if (preg_match('/^8\.\d+$/', $php) !== 1) {
            self::fail("--php must look like 8.1 or 8.5, got: {$php}");
        }

        // The PHP 8.0 downgrade (backed enums to classes, readonly removal,
        // octal literals) rewrites type hints across a dozen consumers and is not
        // safe to do mechanically. cyklodresy is the only site that would need it
        // and it still runs its 1.6.2 copy; redo that one by hand if its box ever
        // moves before it is retired.
        if (version_compare($php, '8.1', '<')) {
            self::fail(
                "--php={$php} is not supported. Backed enums, readonly promotion and 0o literals\n".
                'would all need rewriting; export to PHP 8.1+ or keep the existing hand-made copy.'
            );
        }

        $laravel = (int) $args['laravel'];
        if ($laravel < 8 || $laravel > 13) {
            self::fail("--laravel must be between 8 and 13, got: {$args['laravel']}");
        }

        return new self(
            $root,
            $to.'/packages/devuni/notifier-agent',
            $php,
            $laravel,
            isset($args['dry-run']),
            self::detectVersion($root),
        );
    }

    public function run(): int
    {
        $this->line("Exporting devuni/notifier-agent {$this->version} as a vendored copy");
        $this->line("  target      {$this->target}");
        $this->line("  PHP         {$this->phpVersion}");
        $this->line('  Laravel     '.$this->laravelMajor);
        $this->line('  mode        '.($this->dryRun ? 'dry run (nothing is written)' : 'write'));
        $this->line('');

        $files = $this->collectFiles();
        $this->line('Collected '.count($files).' files.');

        $written = [];
        foreach ($files as $relative => $absolute) {
            $contents = file_get_contents($absolute);
            if ($contents === false) {
                self::fail("Cannot read {$absolute}");
            }

            if (str_ends_with($relative, '.php')) {
                $contents = $this->transform($relative, $contents);
            }

            $written[$relative] = $contents;
        }

        $written['src/Interfaces/ReportStatusInterface.php'] = $this->reportStatusInterface($written);
        $written['README.md'] = $this->readme();

        $this->verify($written);

        if ($this->dryRun) {
            $this->line('');
            $this->line('Dry run - nothing written. Transformations that would apply:');
            $this->summary();

            return 0;
        }

        $this->wipeTarget();

        foreach ($written as $relative => $contents) {
            $path = $this->target.'/'.$relative;
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                self::fail("Cannot create {$dir}");
            }
            file_put_contents($path, $contents);
        }

        $this->line('Wrote '.count($written).' files.');
        $this->summary();
        $this->line('');
        $this->line('Next, in the target project:');
        $this->line('  1. composer.json -> autoload.psr-4: "Devuni\\\\Notifier\\\\": "packages/devuni/notifier-agent/src/"');
        $this->line('  2. register Devuni\\Notifier\\NotifierServiceProvider (config/app.php on Laravel 8-10,');
        $this->line('     bootstrap/providers.php on Laravel 11+) - a local copy has no package discovery');
        $this->line('  3. composer dump-autoload');
        $this->line('  4. set NOTIFIER_URL, NOTIFIER_BACKUP_CODE and NOTIFIER_BACKUP_PASSWORD in .env');
        $this->line('  5. php artisan notifier:check');

        return $this->warnings === [] ? 0 : 1;
    }

    private static function detectVersion(string $root): string
    {
        $changelog = @file_get_contents($root.'/CHANGELOG.md');
        if ($changelog !== false && preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', $changelog, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }

    private static function fail(string $message): never
    {
        fwrite(STDERR, $message."\n");
        exit(1);
    }

    /** @return array<string, string> relative path => absolute path */
    private function collectFiles(): array
    {
        $files = [];

        foreach (['src', 'config', 'routes', 'resources'] as $dir) {
            $base = $this->root.'/'.$dir;
            if (! is_dir($base)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                /** @var SplFileInfo $file */
                if (! $file->isFile()) {
                    continue;
                }

                $relative = str_replace('\\', '/', mb_substr($file->getPathname(), mb_strlen($this->root) + 1));

                if (in_array($relative, self::SKIP_FILES, true)) {
                    $this->applied[] = "dropped {$relative} (needs laravel/prompts)";

                    continue;
                }

                $files[$relative] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    private function transform(string $relative, string $contents): string
    {
        $before = $contents;

        // --- vendoring: laravel/prompts cannot be a dependency of a copy -------
        $contents = str_replace("use function Laravel\\Prompts\\note;\n\n", '', $contents);
        $contents = preg_replace(
            '/^\s*note\((.*)\);$/m',
            '        $this->newLine();'."\n".'        $this->output->writeln($1);'."\n".'        $this->newLine();',
            $contents
        ) ?? $contents;

        // The install command is gone, so pointing at it would be a dead end.
        $contents = str_replace(
            "'Run: php artisan notifier:install'",
            "'Set NOTIFIER_URL, NOTIFIER_BACKUP_CODE and NOTIFIER_BACKUP_PASSWORD in .env'",
            $contents
        );
        $contents = str_replace(
            'Some environment variables are missing. Run notifier:install to set them.',
            'Some environment variables are missing.',
            $contents
        );

        if ($relative === 'src/NotifierServiceProvider.php') {
            $contents = $this->stampVersion($contents);
        }

        // Composer knows nothing about a vendored copy, so getPrettyVersion() would
        // always throw and report "unknown". Read the stamped constant instead -
        // and collapse the guard around it, which now has nothing left to catch.
        $contents = preg_replace(
            '/        try \{\R'
            ."            return InstalledVersions::getPrettyVersion\('devuni\/notifier-agent'\) \?\? 'custom';\R"
            .'        \} catch \(OutOfBoundsException ?\$?\w*\) \{\R'
            ."            return 'unknown';\R"
            .'        \}/',
            '        return NotifierServiceProvider::VERSION;',
            $contents,
            -1,
            $collapsed
        ) ?? $contents;

        if ($collapsed > 0) {
            // Both imports existed only for the lookup that just disappeared.
            $contents = preg_replace('/^use Composer\\\\InstalledVersions;\R/m', '', $contents) ?? $contents;
            $contents = preg_replace('/^use OutOfBoundsException;\R/m', '', $contents) ?? $contents;
        }

        $contents = str_replace(
            "InstalledVersions::getPrettyVersion('devuni/notifier-agent') ?? 'custom'",
            'NotifierServiceProvider::VERSION',
            $contents
        );
        if (str_contains($contents, 'NotifierServiceProvider::VERSION')
            && $relative !== 'src/NotifierServiceProvider.php') {
            $contents = $this->addImport($contents, 'Devuni\\Notifier\\NotifierServiceProvider');
        }

        // --- PHP version downgrades ------------------------------------------
        if (version_compare($this->phpVersion, '8.4', '<')) {
            // The trimmed character lists are ASCII throughout, so these are exact
            // equivalents rather than approximations.
            $contents = preg_replace('/\bmb_(trim|ltrim|rtrim)\(/', '$1(', $contents) ?? $contents;
        }

        if (version_compare($this->phpVersion, '8.3', '<')) {
            // mb_str_pad has no pre-8.3 equivalent that stays multibyte-correct,
            // so pad explicitly by character count.
            $contents = str_replace(
                '$label = mb_str_pad($result[\'label\'], $labelWidth);',
                '$label = $result[\'label\'].str_repeat(\' \', max(0, $labelWidth - mb_strlen($result[\'label\'])));',
                $contents
            );
        }

        if (version_compare($this->phpVersion, '8.2', '<')) {
            $contents = $this->moveTraitConstants($relative, $contents);
        }

        // --- Laravel version downgrades ---------------------------------------
        if ($this->laravelMajor < 9) {
            $contents = $this->downgradeToLaravel8($relative, $contents);
        }

        if ($contents !== $before) {
            $this->applied[] = "rewrote {$relative}";
        }

        return $contents;
    }

    /**
     * Insert a `use` statement in alphabetical position, unless it is already there.
     */
    private function addImport(string $contents, string $fqcn): string
    {
        $statement = "use {$fqcn};";

        if (str_contains($contents, $statement)) {
            return $contents;
        }

        $lines = explode("\n", $contents);
        $insertAt = null;

        foreach ($lines as $index => $line) {
            if (! str_starts_with($line, 'use ')) {
                continue;
            }
            if (mb_rtrim($line, "\r") > $statement) {
                $insertAt = $index;
                break;
            }
            $insertAt = $index + 1;
        }

        if ($insertAt === null) {
            $this->warnings[] = "Could not place `{$statement}` - no use statements found.";

            return $contents;
        }

        array_splice($lines, $insertAt, 0, $statement);

        return implode("\n", $lines);
    }

    private function stampVersion(string $contents): string
    {
        $stamp = <<<PHP
            {
                /**
                 * Version of the vendored copy, stamped by scripts/vendor-export.php.
                 *
                 * A copy is not a composer package, so Composer\\InstalledVersions cannot
                 * report it; the CLI header and the heartbeat manifest read this instead.
                 */
                public const VERSION = '{$this->version}-vendored';

            PHP;

        $result = preg_replace(
            '/^final class NotifierServiceProvider extends ServiceProvider\R\{\R/m',
            "final class NotifierServiceProvider extends ServiceProvider\n".mb_ltrim($stamp),
            $contents,
            1,
            $count
        );

        if ($count !== 1) {
            $this->warnings[] = 'Could not stamp the VERSION constant - the provider class declaration changed shape.';

            return $contents;
        }

        // The install command is not part of the copy.
        $result = preg_replace('/^use Devuni\\\\Notifier\\\\Commands\\\\NotifierInstallCommand;\R/m', '', $result) ?? $result;
        $result = preg_replace('/^\s*NotifierInstallCommand::class,\R/m', '', $result) ?? $result;

        return $result;
    }

    private function moveTraitConstants(string $relative, string $contents): string
    {
        if ($relative === 'src/Traits/RendersReportTrait.php') {
            $stripped = preg_replace(
                '/    protected const STATUS_(PASS|WARN|FAIL) = \'[a-z]+\';\R\R/',
                '',
                $contents,
                -1,
                $count
            ) ?? $contents;

            if ($count !== 3) {
                $this->warnings[] = "Expected 3 STATUS_* constants in the trait, removed {$count}.";
            }

            $stripped = $this->addImport($stripped, 'Devuni\Notifier\Interfaces\ReportStatusInterface');

            return str_replace('self::STATUS_*', 'ReportStatusInterface::STATUS_*', $stripped);
        }

        // Commands consuming the trait must implement the interface, otherwise
        // self::STATUS_* no longer resolves.
        if (str_starts_with($relative, 'src/Commands/') && str_contains($contents, 'use RendersReportTrait;')) {
            $contents = preg_replace(
                '/^(final class \w+ extends Command)$/m',
                '$1 implements ReportStatusInterface',
                $contents,
                1,
                $count
            ) ?? $contents;

            if ($count === 1) {
                // addImport places the statement alphabetically. Inserting before
                // the first Devuni import (the previous approach) left unsorted
                // imports in commands whose earliest import sorts before
                // Interfaces\ReportStatusInterface, and a client's pint gate with
                // ordered_imports then fails on generated files.
                $contents = $this->addImport($contents, 'Devuni\Notifier\Interfaces\ReportStatusInterface');
            } else {
                $this->warnings[] = "Could not add `implements ReportStatusInterface` to {$relative}.";
            }
        }

        return $contents;
    }

    private function downgradeToLaravel8(string $relative, string $contents): string
    {
        if ($relative === 'src/Requests/BackupRequest.php') {
            // Rule::enum() arrived in Laravel 9. Rule::in over the enum's own cases
            // keeps the allowed values derived from BackupTypeEnum.
            $contents = str_replace(
                'Rule::enum(BackupTypeEnum::class)',
                "Rule::in(array_column(BackupTypeEnum::cases(), 'value'))",
                $contents
            );
            // The message key follows the rule name, so it moves with it.
            $contents = str_replace("'type.enum' =>", "'type.in' =>", $contents);
            // validated() with a key is also Laravel 9+.
            $contents = str_replace(
                "BackupTypeEnum::from(\$this->validated('type'))",
                "BackupTypeEnum::from((string) (\$this->validated()['type'] ?? ''))",
                $contents
            );
        }

        // PendingRequest::connectTimeout() is Laravel 9+; the Guzzle option is not.
        $contents = str_replace(
            '->connectTimeout(5)',
            "->withOptions(['connect_timeout' => 5])",
            $contents
        );

        // Response::json('key') only gained the key argument late in the 8.x line;
        // older 8.x silently ignores it and returns the whole payload.
        $contents = preg_replace(
            '/\$response->json\(\'([a-z_]+)\'\)/',
            "data_get(\$response->json(), '$1')",
            $contents
        ) ?? $contents;

        return $contents;
    }

    /** @param array<string, string> $written */
    private function reportStatusInterface(array $written): string
    {
        $trait = $written['src/Traits/RendersReportTrait.php'] ?? '';
        $needsInterface = version_compare($this->phpVersion, '8.2', '<');

        if (! $needsInterface) {
            // Nothing references it, but shipping it keeps the copy's shape stable
            // across profiles and costs one file.
            return $this->interfaceSource('Unused on PHP 8.2+, where the trait may hold its own constants.');
        }

        if (str_contains($trait, 'protected const STATUS_PASS')) {
            $this->warnings[] = 'The trait still declares STATUS_* constants; the interface will collide.';
        }

        return $this->interfaceSource('Constants inside a trait need PHP 8.2; interface constants do not.');
    }

    private function interfaceSource(string $why): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Devuni\\Notifier\\Interfaces;

        /**
         * Status vocabulary shared by the notifier:* command reports.
         *
         * {$why}
         *
         * Generated by scripts/vendor-export.php - do not edit by hand.
         */
        interface ReportStatusInterface
        {
            public const STATUS_PASS = 'pass';

            public const STATUS_WARN = 'warn';

            public const STATUS_FAIL = 'fail';
        }

        PHP;
    }

    /** @param array<string, string> $written */
    private function verify(array $written): void
    {
        $modern = [
            'mb_trim(' => 'PHP 8.4',
            'mb_ltrim(' => 'PHP 8.4',
            'mb_rtrim(' => 'PHP 8.4',
            'mb_str_pad(' => 'PHP 8.3',
        ];

        foreach ($written as $relative => $contents) {
            if (! str_ends_with($relative, '.php')) {
                continue;
            }

            foreach ($modern as $needle => $since) {
                if (str_contains($contents, $needle) && version_compare($this->phpVersion, mb_ltrim($since, 'PHP '), '<')) {
                    $this->warnings[] = "{$relative} still calls {$needle} ({$since}+).";
                }
            }

            if ($this->laravelMajor < 9) {
                foreach (['Rule::enum(' => 'Laravel 9', '->connectTimeout(' => 'Laravel 9'] as $needle => $since) {
                    if (str_contains($contents, $needle)) {
                        $this->warnings[] = "{$relative} still calls {$needle} ({$since}+).";
                    }
                }
            }

            if (str_contains($contents, 'Laravel\\Prompts')) {
                $this->warnings[] = "{$relative} still references laravel/prompts.";
            }

            $syntax = $this->lint($contents);
            if ($syntax !== null) {
                $this->warnings[] = "{$relative} does not parse: {$syntax}";
            }
        }
    }

    private function lint(string $contents): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'nve');
        if ($tmp === false) {
            return null;
        }

        file_put_contents($tmp, $contents);
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($tmp).' 2>&1', $output, $status);
        unlink($tmp);

        return $status === 0 ? null : mb_trim(implode(' ', $output));
    }

    private function readme(): string
    {
        $transforms = [];

        if (version_compare($this->phpVersion, '8.4', '<')) {
            $transforms[] = '-   `mb_trim` / `mb_ltrim` / `mb_rtrim` (PHP 8.4) -> `trim` / `ltrim` / `rtrim`. The trimmed character lists are ASCII, so these are exact equivalents.';
        }
        if (version_compare($this->phpVersion, '8.3', '<')) {
            $transforms[] = '-   `mb_str_pad` (PHP 8.3) -> explicit padding by character count, so multibyte labels stay aligned.';
        }
        if (version_compare($this->phpVersion, '8.2', '<')) {
            $transforms[] = '-   `STATUS_*` constants moved out of `RendersReportTrait` into `Interfaces\\ReportStatusInterface` (constants in traits need PHP 8.2). Every command implements it, so `self::STATUS_*` still resolves.';
        }
        if ($this->laravelMajor < 9) {
            $transforms[] = '-   `Rule::enum()` -> `Rule::in()` over the enum\'s own cases, and the message key moves from `type.enum` to `type.in`.';
            $transforms[] = '-   `$this->validated(\'type\')` -> `$this->validated()[\'type\']` (the keyed signature is Laravel 9+).';
            $transforms[] = '-   `->connectTimeout(5)` -> `->withOptions([\'connect_timeout\' => 5])`.';
            $transforms[] = '-   `$response->json(\'key\')` -> `data_get($response->json(), \'key\')`; old Laravel 8 ignores the argument and returns the whole payload.';
        }

        $transformList = $transforms === []
            ? "None - the target runs a PHP and Laravel version the source already supports. Only the vendoring changes below apply.\n"
            : implode("\n", $transforms)."\n";

        $date = date('Y-m-d');

        return <<<MD
        # devuni/notifier-agent - vendored copy ({$this->version}-vendored)

        Generated by `scripts/vendor-export.php` from **devuni/notifier-agent {$this->version}**
        on {$date}, for **PHP {$this->phpVersion}+ / Laravel {$this->laravelMajor}**.

        **Do not edit these files.** Re-run the exporter after every upstream release:

        ```bash
        php scripts/vendor-export.php --to=<this project> --php={$this->phpVersion} --laravel={$this->laravelMajor}
        ```

        The composer package requires PHP 8.4+ and Laravel 12-13, which this project cannot
        satisfy, so the source lives here and is loaded through PSR-4 instead. The namespace
        (`Devuni\\Notifier`), the env variables and the artisan commands are identical to the
        composer package - only the delivery mechanism differs.

        ## Vendoring changes (always applied)

        -   `notifier:install` is dropped: it needs `laravel/prompts`, which a copy without its
            own `composer.json` cannot depend on. Configure `.env` by hand and verify with
            `php artisan notifier:check`.
        -   The version is stamped into `NotifierServiceProvider::VERSION`, because
            `Composer\\InstalledVersions` cannot see a copy and would report `unknown`.

        ## Version transformations

        {$transformList}
        ## Installation

        1.  `composer.json` -> `autoload.psr-4`: `"Devuni\\\\Notifier\\\\": "packages/devuni/notifier-agent/src/"`
        2.  Register `Devuni\\Notifier\\NotifierServiceProvider` (`config/app.php` on Laravel 8-10,
            `bootstrap/providers.php` on Laravel 11+) - a local copy has no package discovery.
        3.  `composer dump-autoload`
        4.  `.env`: `NOTIFIER_URL`, `NOTIFIER_BACKUP_CODE`, `NOTIFIER_BACKUP_PASSWORD`
        5.  `php artisan notifier:check`

        ## Moving to the composer package later

        Once the project runs PHP 8.4+ and Laravel 12+: delete this directory, drop the PSR-4
        mapping and the manual provider registration, then `composer require devuni/notifier-agent`.
        Nothing else in the project changes.
        MD;
    }

    private function wipeTarget(): void
    {
        if (! is_dir($this->target)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->target, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
    }

    private function summary(): void
    {
        $this->line('');
        foreach ($this->applied as $entry) {
            $this->line("  - {$entry}");
        }

        if ($this->warnings !== []) {
            $this->line('');
            $this->line('WARNINGS:');
            foreach ($this->warnings as $warning) {
                $this->line("  ! {$warning}");
            }
        }
    }

    private function line(string $message): void
    {
        fwrite(STDOUT, $message."\n");
    }
}

exit(VendorExport::fromArgv($argv)->run());
