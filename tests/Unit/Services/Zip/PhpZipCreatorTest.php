<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Zip\PhpZipCreator;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| PhpZipCreator is the always-available fallback that produces the
| AES-256-encrypted backup archive. These prove the core guarantee: the
| archive really is encrypted (only the right password reveals contents).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/phpzip-'.uniqid();
    $this->source = $this->work.'/source';
    $this->zipPath = $this->work.'/backup.zip';
    File::ensureDirectoryExists($this->source);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

function openArchive(string $path, ?string $password = null): ZipArchive
{
    $zip = new ZipArchive;
    $zip->open($path);

    if ($password !== null) {
        $zip->setPassword($password);
    }

    return $zip;
}

it('creates an AES-256 archive from a single file that the right password decrypts', function () {
    $file = $this->source.'/dump.sql';
    File::put($file, 'SECRET DATABASE CONTENTS');

    $count = app(PhpZipCreator::class)->create($file, $this->zipPath, 'strong-password');

    expect($count)->toBe(1)
        ->and(file_exists($this->zipPath))->toBeTrue();

    $zip = openArchive($this->zipPath, 'strong-password');
    expect($zip->getFromName('dump.sql'))->toBe('SECRET DATABASE CONTENTS');
    $zip->close();
});

it('does not reveal contents with the wrong password (encryption holds)', function () {
    File::put($this->source.'/dump.sql', 'SECRET');

    app(PhpZipCreator::class)->create($this->source.'/dump.sql', $this->zipPath, 'right-password');

    $zip = openArchive($this->zipPath, 'wrong-password');
    expect($zip->getFromName('dump.sql'))->toBeFalse();
    $zip->close();
});

it('marks every entry as AES-256 encrypted', function () {
    File::put($this->source.'/a.txt', 'A');
    File::put($this->source.'/b.txt', 'B');

    app(PhpZipCreator::class)->create($this->source, $this->zipPath, 'pass');

    $zip = openArchive($this->zipPath);
    foreach (['a.txt', 'b.txt'] as $name) {
        $stat = $zip->statName($name);
        expect($stat['encryption_method'])->toBe(ZipArchive::EM_AES_256);
    }
    $zip->close();
});

it('archives a directory tree and reports the file count', function () {
    File::put($this->source.'/a.txt', 'A');
    File::ensureDirectoryExists($this->source.'/sub');
    File::put($this->source.'/sub/b.txt', 'B');

    $count = app(PhpZipCreator::class)->create($this->source, $this->zipPath, 'pass');

    expect($count)->toBe(2);

    $zip = openArchive($this->zipPath, 'pass');
    expect($zip->getFromName('a.txt'))->toBe('A')
        ->and($zip->getFromName('sub/b.txt'))->toBe('B');
    $zip->close();
});

it('skips excluded paths', function () {
    File::put($this->source.'/keep.txt', 'K');
    File::ensureDirectoryExists($this->source.'/secret');
    File::put($this->source.'/secret/x.env', 'X');

    $count = app(PhpZipCreator::class)->create($this->source, $this->zipPath, 'pass', ['secret']);

    expect($count)->toBe(1);

    $zip = openArchive($this->zipPath);
    expect($zip->locateName('secret/x.env'))->toBeFalse()
        ->and($zip->locateName('keep.txt'))->not->toBeFalse();
    $zip->close();
});

it('throws and removes the archive when the directory has no files', function () {
    expect(fn () => app(PhpZipCreator::class)->create($this->source, $this->zipPath, 'pass'))
        ->toThrow(RuntimeException::class, 'No files to backup');

    expect(file_exists($this->zipPath))->toBeFalse();
});
