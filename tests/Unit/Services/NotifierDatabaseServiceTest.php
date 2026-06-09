<?php

declare(strict_types=1);

use Devuni\Notifier\Interfaces\DatabaseDumperInterface;
use Devuni\Notifier\Interfaces\ZipCreatorInterface;
use Devuni\Notifier\Services\ChunkedUploadService;
use Devuni\Notifier\Services\Database\LazyDatabaseDumper;
use Devuni\Notifier\Services\NotifierDatabaseService;
use Devuni\Notifier\Services\NotifierLoggerService;

/**
 * A configurable in-memory dumper. `content` controls what the dump writes:
 *   - a non-empty string -> a valid dump file
 *   - an empty string    -> an empty dump file (triggers the "is empty" guard)
 *   - null               -> no file at all  (triggers the "was not created" guard)
 */
function fakeDatabaseDumper(?string $content = 'SQL DUMP CONTENT'): DatabaseDumperInterface
{
    return new class($content) implements DatabaseDumperInterface
    {
        public string $dumpedTo = '';

        public function __construct(public ?string $content) {}

        public static function isAvailable(): bool
        {
            return true;
        }

        public function dump(string $outputPath): void
        {
            $this->dumpedTo = $outputPath;

            if ($this->content !== null) {
                file_put_contents($outputPath, $this->content);
            }
        }

        public function describe(): string
        {
            return 'fake-dumper 1.0';
        }
    };
}

/**
 * A ZIP creator that records what it was asked to archive and writes a stub archive.
 */
function fakeZipCreator(): ZipCreatorInterface
{
    return new class implements ZipCreatorInterface
    {
        /** @var array{source: string, zip: string, password: string}|null */
        public ?array $captured = null;

        public static function isAvailable(): bool
        {
            return true;
        }

        public function create(string $sourcePath, string $zipPath, string $password, array $excludedFiles = []): int
        {
            $this->captured = ['source' => $sourcePath, 'zip' => $zipPath, 'password' => $password];
            file_put_contents($zipPath, 'ZIP-ARCHIVE');

            return 1;
        }
    };
}

function makeNotifierDatabaseService(
    DatabaseDumperInterface $dumper,
    ZipCreatorInterface $zip,
): NotifierDatabaseService {
    return new NotifierDatabaseService(
        new ChunkedUploadService(new NotifierLoggerService()),
        $zip,
        $dumper,
        new NotifierLoggerService(),
    );
}

describe('NotifierDatabaseService::createDatabaseBackup', function () {
    it('returns a plain .sql file when no zip password is configured', function () {
        config(['notifier.backup_zip_password' => null]);

        $dumper = fakeDatabaseDumper('SELECT 1;');
        $zip = fakeZipCreator();

        $path = makeNotifierDatabaseService($dumper, $zip)->createDatabaseBackup();

        try {
            expect($path)->toEndWith('.sql');
            expect(file_exists($path))->toBeTrue();
            expect($dumper->dumpedTo)->toBe($path);
            // ZIP must NOT be invoked when there is no password.
            expect($zip->captured)->toBeNull();
        } finally {
            @unlink($path);
        }
    });

    it('encrypts the dump into a password-protected ZIP when a password is set', function () {
        config(['notifier.backup_zip_password' => 'super-secret']);

        $dumper = fakeDatabaseDumper('SELECT 1;');
        $zip = fakeZipCreator();

        $path = makeNotifierDatabaseService($dumper, $zip)->createDatabaseBackup();

        try {
            expect($path)->toEndWith('.zip');
            expect($zip->captured)->not->toBeNull();
            expect($zip->captured['password'])->toBe('super-secret');
            expect($zip->captured['source'])->toEndWith('.sql');
            // The intermediate .sql dump is deleted once it has been encrypted.
            expect(file_exists($zip->captured['source']))->toBeFalse();
        } finally {
            @unlink($path);
        }
    });

    it('throws when the dump command produced no file', function () {
        config(['notifier.backup_zip_password' => null]);

        $service = makeNotifierDatabaseService(fakeDatabaseDumper(null), fakeZipCreator());

        expect(fn () => $service->createDatabaseBackup())
            ->toThrow(RuntimeException::class, 'was not created');
    });

    it('throws when the dump file is empty', function () {
        config(['notifier.backup_zip_password' => null]);

        $service = makeNotifierDatabaseService(fakeDatabaseDumper(''), fakeZipCreator());

        expect(fn () => $service->createDatabaseBackup())
            ->toThrow(RuntimeException::class, 'is empty');
    });

    it('unwraps a LazyDatabaseDumper and resolves it exactly once across logging + dump', function () {
        config(['notifier.backup_zip_password' => null]);

        $inner = fakeDatabaseDumper('SELECT 1;');
        $resolverCalls = 0;
        $lazy = new LazyDatabaseDumper(function () use (&$resolverCalls, $inner): DatabaseDumperInterface {
            $resolverCalls++;

            return $inner;
        });

        $path = makeNotifierDatabaseService($lazy, fakeZipCreator())->createDatabaseBackup();

        try {
            // The diagnostic-log unwrap and the actual dump() share a single resolution.
            expect($resolverCalls)->toBe(1);
            expect(file_exists($path))->toBeTrue();
        } finally {
            @unlink($path);
        }
    });
});
