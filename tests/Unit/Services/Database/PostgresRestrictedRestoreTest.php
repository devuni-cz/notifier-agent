<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Database\PostgresImporter;
use Devuni\Notifier\Services\Database\SqlDirectiveGuard;
use Devuni\Notifier\Services\NotifierLoggerService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;

/*
|--------------------------------------------------------------------------
| The fake client stands in for psql on every platform and records the exact
| arguments and bytes it was given, so import() is asserted end to end
| without a database.
|--------------------------------------------------------------------------
*/

function fakePostgresClient(string $directory, string $version, int $exitCode = 0): string
{
    $script = $directory.DIRECTORY_SEPARATOR.'client.php';
    $calls = $directory.DIRECTORY_SEPARATOR.'calls.jsonl';

    file_put_contents($script, '<?php
        $arguments = array_slice($argv, 1);

        if ($arguments === ["--version"]) {
            echo '.var_export($version, true).', PHP_EOL;
            exit(0);
        }

        $file = "";

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, "--file=")) {
                $file = substr($argument, 7);
            }
        }

        $content = $file === "-" ? stream_get_contents(STDIN) : file_get_contents($file);

        file_put_contents('.var_export($calls, true).', json_encode([
            "arguments" => $arguments,
            "script" => base64_encode($content),
        ]).PHP_EOL, FILE_APPEND);

        exit('.$exitCode.');
    ');

    if (PHP_OS_FAMILY === 'Windows') {
        $binary = $directory.DIRECTORY_SEPARATOR.'client.bat';
        file_put_contents($binary, '@"'.PHP_BINARY.'" "'.$script.'" %*'."\r\n");

        return $binary;
    }

    $binary = $directory.DIRECTORY_SEPARATOR.'client';
    file_put_contents($binary, "#!/bin/sh\nexec '".PHP_BINARY."' '".$script."' \"\$@\"\n");
    chmod($binary, 0755);

    return $binary;
}

/**
 * @return list<array{arguments: list<string>, script: string}>
 */
function fakeClientCalls(string $directory): array
{
    return array_map(
        static function (string $line): array {
            $call = json_decode($line, true);
            $call['script'] = base64_decode($call['script']);

            return $call;
        },
        file($directory.DIRECTORY_SEPARATOR.'calls.jsonl', FILE_IGNORE_NEW_LINES),
    );
}

function restoreWithFakeClient(string $directory, string $version, string $dump, int $exitCode = 0): string
{
    config()->set('notifier.postgres_restore_binary', fakePostgresClient($directory, $version, $exitCode));
    config()->set('database.connections.pgsql', ['database' => 'shop', 'username' => 'u', 'host' => 'h', 'port' => 5432]);

    $path = $directory.DIRECTORY_SEPARATOR.'dump.sql';
    file_put_contents($path, $dump);

    (new PostgresImporter('pgsql', new NotifierLoggerService))->import($path);

    return $path;
}

/**
 * @return list<string> Files in the directory that neither the test nor the fake client created.
 */
function leftoverRestoreFiles(string $directory): array
{
    return array_values(array_diff(
        scandir($directory),
        ['.', '..', 'client', 'client.bat', 'client.php', 'calls.jsonl', 'dump.sql'],
    ));
}

function recordRestoreLog(): AbstractLogger
{
    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = $level.': '.$message;
        }
    };

    Log::shouldReceive('channel')->andReturn($logger);

    return $logger;
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'restricted_restore_'.bin2hex(random_bytes(6));
    mkdir($this->directory);
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

describe('PostgresImporter::buildCommand', function () {
    it('enters restricted mode right before the schema reset', function () {
        $argv = (new PostgresImporter('pgsql', new NotifierLoggerService))
            ->buildCommand('psql', ['username' => 'u'], 'shop', '-', 'secretkey');

        $restrict = array_search('--command=\restrict secretkey', $argv, true);

        expect($restrict)->toBeInt()
            ->and($argv[$restrict + 1])->toStartWith('--command=DROP SCHEMA')
            ->and($argv[$restrict + 2])->toBe('--file=-');
    });

    it('stays out of restricted mode without a key', function () {
        $argv = (new PostgresImporter('pgsql', new NotifierLoggerService))
            ->buildCommand('psql', ['username' => 'u'], 'shop', '/tmp/d.sql');

        expect(implode(' ', $argv))->not->toContain('restrict')
            ->and($argv)->toContain('--file=/tmp/d.sql');
    });
});

describe('PostgresImporter::supportsRestrictedMode', function () {
    it('recognises the client releases that have restricted mode', function (string $version) {
        expect(PostgresImporter::supportsRestrictedMode($version))->toBeTrue();
    })->with([
        'first 13' => ['psql (PostgreSQL) 13.22'],
        'first 14' => ['psql (PostgreSQL) 14.19'],
        'first 15' => ['psql (PostgreSQL) 15.14'],
        'first 16' => ['psql (PostgreSQL) 16.10'],
        'later 16' => ['psql (PostgreSQL) 16.15'],
        'first 17' => ['psql (PostgreSQL) 17.6'],
        '18' => ['psql (PostgreSQL) 18.0'],
        'newer than 18' => ['psql (PostgreSQL) 19.2'],
        'distribution suffix' => ['psql (PostgreSQL) 16.10 (Ubuntu 16.10-1.pgdg24.04+1)'],
    ]);

    it('treats every other client as lacking restricted mode', function (?string $version) {
        expect(PostgresImporter::supportsRestrictedMode($version))->toBeFalse();
    })->with([
        'last 13 without it' => ['psql (PostgreSQL) 13.21'],
        'last 14 without it' => ['psql (PostgreSQL) 14.18'],
        'last 15 without it' => ['psql (PostgreSQL) 15.13'],
        'last 16 without it' => ['psql (PostgreSQL) 16.9'],
        'last 17 without it' => ['psql (PostgreSQL) 17.5'],
        'major without a fix' => ['psql (PostgreSQL) 12.22'],
        'ysqlsh on an old base' => ['ysqlsh (PostgreSQL) 11.2-YB-2.20.1.0-b0'],
        'ysqlsh on a patched base' => ['ysqlsh (PostgreSQL) 15.14'],
        'fork presenting itself as psql' => ['psql (PostgreSQL) 15.14-YB-2025.1.0.0-b0'],
        'prerelease' => ['psql (PostgreSQL) 18beta1'],
        'unrelated output' => ['command not understood'],
        'empty output' => [''],
        'version unknown' => [null],
    ]);
});

describe('SqlDirectiveGuard::postgresRestrictLines', function () {
    it('reports the byte position of each line', function (string $dump, array $expected) {
        $path = $this->directory.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($path, $dump);

        expect(SqlDirectiveGuard::postgresRestrictLines($path))->toBe($expected);
    })->with([
        'pg_dump layout' => ["\\restrict ab\n\nSELECT 1;\n\n\\unrestrict ab\n\n", [[0, 12], [25, 14]]],
        'indented' => ["  \\restrict ab\n", [[2, 12]]],
        'CRLF ending stays with the line' => ["\\restrict ab\r\nSELECT 1;\r\n", [[0, 13]]],
        'no final line feed' => ["SELECT 1;\n\\unrestrict ab", [[10, 14]]],
        'none' => ["SELECT 1;\n", []],
    ]);

    it('keeps the positions exact past the read window', function () {
        $prefix = 'SELECT \''.str_repeat('x', 3_000_000)."';\n";
        $path = $this->directory.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($path, $prefix."\\unrestrict ab\n");

        expect(SqlDirectiveGuard::postgresRestrictLines($path))->toBe([[mb_strlen($prefix), 14]]);
    });

    it('does not take stored text for a restrict line', function (string $dump) {
        $path = $this->directory.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($path, $dump);

        expect(SqlDirectiveGuard::postgresRestrictLines($path))->toBe([]);
    })->with([
        'COPY data' => ["COPY t (v) FROM stdin;\n\\restrict ab\n\\.\n"],
        'string' => ["SELECT 'a\n\\restrict ab\nb';\n"],
        'dollar quote' => ["SELECT \$q\$\n\\unrestrict ab\n\$q\$;\n"],
        'block comment' => ["/*\n\\restrict ab\n*/\n"],
    ]);

    it('refuses more restrict lines than a dump can hold', function () {
        $path = $this->directory.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($path, "\\restrict ab\n\\unrestrict ab\n\\restrict ab\n");

        expect(fn () => SqlDirectiveGuard::postgresRestrictLines($path))
            ->toThrow(RuntimeException::class, 'line 3');
    });
});

describe('PostgresImporter::withoutLines', function () {
    it('leaves out exactly the given ranges', function (string $content, array $ranges, string $expected) {
        $path = $this->directory.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($path, $content);

        $importer = new PostgresImporter('pgsql', new NotifierLoggerService);

        expect(implode('', iterator_to_array($importer->withoutLines($path, $ranges), false)))->toBe($expected);
    })->with([
        'nothing to leave out' => ['abcdef', [], 'abcdef'],
        'at the start' => ['abcdef', [[0, 2]], 'cdef'],
        'at the end' => ['abcdef', [[4, 2]], 'abcd'],
        'two ranges' => ['abcdef', [[1, 1], [4, 1]], 'acdf'],
        'adjacent ranges' => ['abcdef', [[1, 1], [2, 1]], 'adef'],
        'binary bytes' => ["a\x00\x1A\r\n\xFFb", [[1, 1]], "a\x1A\r\n\xFFb"],
        'empty file' => ['', [], ''],
    ]);

    it('works in chunks across a range that spans a chunk boundary', function () {
        $head = str_repeat('a', 65_530);
        $tail = str_repeat('b', 200_000);
        $path = $this->directory.DIRECTORY_SEPARATOR.'dump.sql';
        file_put_contents($path, $head.'0123456789012'.$tail);

        $chunks = iterator_to_array(
            (new PostgresImporter('pgsql', new NotifierLoggerService))->withoutLines($path, [[65_530, 13]]),
            false,
        );

        expect(implode('', $chunks))->toBe($head.$tail)
            ->and(max(array_map(strlen(...), $chunks)))->toBeLessThanOrEqual(65_536);
    });
});

describe('PostgresImporter::import', function () {
    $dump = "\\restrict abc123\n\nCREATE TABLE t (v text);\nCOPY t (v) FROM stdin;\n\\restrict abc123\n\\.\n"
        ."COMMENT ON TABLE t IS 'x\n\\unrestrict abc123\ny';\n\n\\unrestrict abc123\n\n";

    $withoutFraming = "\n\nCREATE TABLE t (v text);\nCOPY t (v) FROM stdin;\n\\restrict abc123\n\\.\n"
        ."COMMENT ON TABLE t IS 'x\n\\unrestrict abc123\ny';\n\n\n\n";

    it('locks the client with a fresh key and removes only the framing lines', function () use ($dump, $withoutFraming) {
        restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);

        [$call] = fakeClientCalls($this->directory);
        $restrict = array_values(array_filter($call['arguments'], static fn (string $argument) => str_starts_with($argument, '--command=\\restrict ')));

        expect($restrict)->toHaveCount(1)
            ->and($restrict[0])->toMatch('/^--command=\\\\restrict [0-9a-f]{64}$/')
            ->and($call['script'])->toBe($withoutFraming);
    });

    it('feeds the client through standard input outside Windows', function () use ($dump) {
        restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);

        expect(fakeClientCalls($this->directory)[0]['arguments'])->toContain('--file=-');
    })->skipOnWindows();

    it('feeds the client a working copy on Windows and removes it', function () use ($dump) {
        $path = restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);

        $file = array_values(array_filter(fakeClientCalls($this->directory)[0]['arguments'], static fn (string $argument) => str_starts_with($argument, '--file=')));

        expect($file[0])->not->toBe('--file=-')->not->toBe('--file='.$path)
            ->and(leftoverRestoreFiles($this->directory))->toBe([]);
    })->onlyOnWindows();

    it('leaves the dump itself untouched', function () use ($dump) {
        $path = restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);

        expect(file_get_contents($path))->toBe($dump);
    });

    it('uses a different key for every restore', function () use ($dump) {
        restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);
        restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);

        $keys = array_map(
            static fn (array $call) => preg_grep('/^--command=\\\\restrict /', $call['arguments']),
            fakeClientCalls($this->directory),
        );

        expect(array_values($keys[0]))->not->toBe(array_values($keys[1]));
    });

    it('locks the client and reads the dump directly when it has no framing lines', function () {
        $dump = "CREATE TABLE t (v text);\n";
        $path = restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump);

        [$call] = fakeClientCalls($this->directory);

        expect($call['arguments'])->toContain('--file='.$path)
            ->and(preg_grep('/^--command=\\\\restrict /', $call['arguments']))->toHaveCount(1)
            ->and($call['script'])->toBe($dump);
    });

    it('restores as before and warns on a client without restricted mode', function (string $version) use ($dump) {
        $logger = recordRestoreLog();
        $path = restoreWithFakeClient($this->directory, $version, $dump);

        [$call] = fakeClientCalls($this->directory);

        expect($call['arguments'])->toContain('--file='.$path)
            ->and(preg_grep('/^--command=\\\\restrict /', $call['arguments']))->toBe([])
            ->and($call['script'])->toBe($dump)
            ->and($logger->records)->toHaveCount(1)
            ->and($logger->records[0])->toStartWith('warning: ')->toContain('restricted mode');
    })->with([
        'older psql' => ['psql (PostgreSQL) 16.9'],
        'ysqlsh' => ['ysqlsh (PostgreSQL) 11.2-YB-2.20.1.0-b0'],
    ]);

    it('reports a failed restore and leaves nothing behind', function () use ($dump) {
        expect(fn () => restoreWithFakeClient($this->directory, 'psql (PostgreSQL) 16.15', $dump, 3))
            ->toThrow(RuntimeException::class, 'Database restore failed');

        expect(leftoverRestoreFiles($this->directory))->toBe([]);
    });
});
