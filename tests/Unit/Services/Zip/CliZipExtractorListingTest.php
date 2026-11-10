<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Zip\CliZipExtractor;

/*
|--------------------------------------------------------------------------
| How a `7z l -slt` listing is read. Pure text, so it is covered on hosts with
| no 7z installed.
|--------------------------------------------------------------------------
*/

function sltListing(string ...$entries): string
{
    return "7-Zip 23.01\n\nListing archive: a.zip\n\n--\nPath = a.zip\nType = zip\nPhysical Size = 100\n\n----------\n"
        .implode("\n\n", $entries)."\n";
}

function sltEntry(string $path, string $folder = '-', string $encrypted = '+', int $size = 10, int $packed = 38, string $comment = ''): string
{
    return "Path = {$path}\nFolder = {$folder}\nSize = {$size}\nPacked Size = {$packed}\nEncrypted = {$encrypted}\nComment = {$comment}\nCRC = 0";
}

it('accepts a listing of encrypted files', function (string $listing) {
    expect(CliZipExtractor::everyFileEntryEncrypted($listing))->toBeTrue();
})->with([
    'one file' => [sltListing(sltEntry('dump.sql'))],
    'a file under a plain directory entry' => [sltListing(sltEntry('d/', folder: '+', encrypted: '-', size: 0), sltEntry('d/a.txt'))],
    'windows line endings' => [str_replace("\n", "\r\n", sltListing(sltEntry('dump.sql')))],
]);

it('refuses a listing that is not all encrypted files', function (string $listing) {
    expect(CliZipExtractor::everyFileEntryEncrypted($listing))->toBeFalse();
})->with([
    'a plaintext file' => [sltListing(sltEntry('dump.sql', encrypted: '-'))],
    'plaintext beside encrypted' => [sltListing(sltEntry('a.txt'), sltEntry('b.txt', encrypted: '-'))],
    'directories only' => [sltListing(sltEntry('d/', folder: '+', encrypted: '-', size: 0))],
    'no entries' => [sltListing()],
    'windows line endings' => [str_replace("\n", "\r\n", sltListing(sltEntry('dump.sql', encrypted: '-')))],
    'extra lines before the real entry' => [sltListing(sltEntry("a\nEncrypted = +\n\nX.sql", encrypted: '-'))],
    'extra carriage-return lines' => [sltListing(sltEntry("a\r\nEncrypted = +\r\n\r\nX.sql", encrypted: '-'))],
    'an extra folder line' => [sltListing(sltEntry("a\nFolder = +", encrypted: '-'))],
    'an extra folder line in the previous comment' => [sltListing(sltEntry('a.txt', comment: "x\nFolder = +"), sltEntry('b.txt', encrypted: '-'))],
    'a marker in the archive header' => [str_replace('Type = zip', "Comment = ----------\nType = zip", sltListing(sltEntry('dump.sql', encrypted: '-')))],
]);

it('adds up every declared size', function () {
    expect(CliZipExtractor::listedSizes(sltListing(
        sltEntry('a.txt', size: 100, packed: 40),
        sltEntry('b.txt', size: 200, packed: 50),
    )))->toBe([300, 90]);
});

it('counts a real size however the entry is named', function () {
    [$uncompressed] = CliZipExtractor::listedSizes(sltListing(sltEntry("a\nSize = 1", size: 5000)));

    expect($uncompressed)->toBeGreaterThanOrEqual(5000);
});

it('reads entries only, not the archive-level header', function () {
    $listing = str_replace('Type = zip', "Type = zip\nSize = 999\nEncrypted = -", sltListing(sltEntry('a.txt', size: 7)));

    expect(CliZipExtractor::listedSizes($listing)[0])->toBe(7)
        ->and(CliZipExtractor::everyFileEntryEncrypted($listing))->toBeTrue();
});

it('does not read a compressed size as a declared one', function () {
    expect(CliZipExtractor::listedSizes(sltListing(sltEntry('a.txt', size: 7, packed: 999)))[0])->toBe(7);
});
