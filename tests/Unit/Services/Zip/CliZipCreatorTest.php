<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Zip\CliZipCreator;
use Devuni\Notifier\Services\Zip\CliZipExtractor;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| CliZipCreator drives the 7z CLI. The behavioural archive test only runs
| where 7z is installed (CI); the empty-directory guard runs everywhere
| because it short-circuits before 7z is invoked.
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/clizip-'.uniqid();
    $this->source = $this->work.'/source';
    $this->zipPath = $this->work.'/backup.zip';
    File::ensureDirectoryExists($this->source);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('reports its availability as a boolean without throwing', function () {
    expect(CliZipCreator::isAvailable())->toBeBool();
});

it('throws when the source directory has no files (before invoking 7z)', function () {
    expect(fn () => app(CliZipCreator::class)->create($this->source, $this->zipPath, 'pass'))
        ->toThrow(RuntimeException::class, 'No files to backup');
});

it('produces an AES-256 archive that the right password decrypts', function () {
    File::put($this->source.'/dump.sql', 'SECRET DATABASE CONTENTS');

    $count = app(CliZipCreator::class)->create($this->source, $this->zipPath, 'strong-password');

    expect($count)->toBeGreaterThanOrEqual(1)
        ->and(file_exists($this->zipPath))->toBeTrue();

    // 7z is the reader the auto strategy pairs with these archives, and it verifies
    // the AES authentication code. The right password goes first so the failure
    // below cannot pass merely because 7z itself is broken.
    $extractor = app(CliZipExtractor::class);
    $extractor->extract($this->zipPath, $this->work.'/right', 'strong-password');
    expect(File::get($this->work.'/right/dump.sql'))->toBe('SECRET DATABASE CONTENTS');

    expect(fn () => $extractor->extract($this->zipPath, $this->work.'/wrong', 'wrong-password'))
        ->toThrow(RuntimeException::class);

    // An independent reader must agree the entry is AES-256 and decrypts it. A
    // single fread on purpose: on libzip before 1.9 the next read reports 7-Zip's
    // zero CRC as an error, and getFromName() makes that read since PHP 8.4.26
    // (see PhpZipExtractor::isUnverifiableByLibzip()).
    $zip = new ZipArchive;
    $zip->open($this->zipPath);
    $zip->setPassword('strong-password');
    expect($zip->statName('dump.sql')['encryption_method'])->toBe(ZipArchive::EM_AES_256);
    $stream = $zip->getStream('dump.sql');
    expect(fread($stream, 24))->toBe('SECRET DATABASE CONTENTS');
    fclose($stream);
    $zip->close();

    $wrong = new ZipArchive;
    $wrong->open($this->zipPath);
    $wrong->setPassword('wrong-password');
    expect($wrong->getFromName('dump.sql'))->toBeFalse();
    $wrong->close();
})->skip(! CliZipCreator::isAvailable(), '7z CLI not available in this environment');
