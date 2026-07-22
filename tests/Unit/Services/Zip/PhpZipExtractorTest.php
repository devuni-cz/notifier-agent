<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Zip\PhpZipCreator;
use Devuni\Notifier\Services\Zip\PhpZipExtractor;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| PhpZipExtractor is the restore-side counterpart of PhpZipCreator. These
| prove the round trip works, that a wrong password fails loudly rather
| than silently producing garbage, and that a hostile archive cannot write
| outside the destination (zip-slip).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->work = sys_get_temp_dir().'/phpunzip-'.uniqid();
    $this->source = $this->work.'/source';
    $this->destination = $this->work.'/restored';
    $this->zipPath = $this->work.'/backup.zip';
    File::ensureDirectoryExists($this->source);
    File::ensureDirectoryExists($this->destination);
});

afterEach(function () {
    File::deleteDirectory($this->work);
});

it('round-trips a single-file backup created by PhpZipCreator', function () {
    $file = $this->source.'/dump.sql';
    File::put($file, 'SECRET DATABASE CONTENTS');

    app(PhpZipCreator::class)->create($file, $this->zipPath, 'strong-password');

    $written = app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'strong-password');

    expect($written)->toBe(1)
        ->and(File::get($this->destination.'/dump.sql'))->toBe('SECRET DATABASE CONTENTS');
});

it('round-trips a nested directory and preserves the tree', function () {
    File::ensureDirectoryExists($this->source.'/nested/deeper');
    File::put($this->source.'/top.txt', 'top');
    File::put($this->source.'/nested/mid.txt', 'mid');
    File::put($this->source.'/nested/deeper/leaf.txt', 'leaf');

    app(PhpZipCreator::class)->create($this->source, $this->zipPath, 'pw');

    $written = app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw');

    expect($written)->toBe(3)
        ->and(File::get($this->destination.'/top.txt'))->toBe('top')
        ->and(File::get($this->destination.'/nested/mid.txt'))->toBe('mid')
        ->and(File::get($this->destination.'/nested/deeper/leaf.txt'))->toBe('leaf');
});

it('fails loudly on a wrong password instead of writing garbage', function () {
    File::put($this->source.'/dump.sql', 'SECRET DATABASE CONTENTS');

    app(PhpZipCreator::class)->create($this->source.'/dump.sql', $this->zipPath, 'right-password');

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'wrong-password'))
        ->toThrow(RuntimeException::class);
});

it('refuses an archive entry that traverses outside the destination', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('../escaped.txt', 'pwned');
    $zip->close();

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, ''))
        ->toThrow(RuntimeException::class, 'escaping the destination');

    expect(File::exists(dirname($this->destination).'/escaped.txt'))->toBeFalse();
});

it('refuses an archive entry with an absolute path', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('/etc/pwned.txt', 'pwned');
    $zip->close();

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, ''))
        ->toThrow(RuntimeException::class, 'absolute path');
});

it('reports a missing archive rather than silently succeeding', function () {
    expect(fn () => app(PhpZipExtractor::class)->extract($this->work.'/nope.zip', $this->destination, 'pw'))
        ->toThrow(RuntimeException::class, 'Archive not found');
});
