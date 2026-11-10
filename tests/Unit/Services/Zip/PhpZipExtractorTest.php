<?php

declare(strict_types=1);

use Devuni\Notifier\Services\Zip\CliZipCreator;
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

it('refuses a plaintext archive when a backup password is configured', function () {
    // A hostile control plane could hand back an UNENCRYPTED zip; ZipArchive would
    // happily read it and ignore setPassword(), silently bypassing the password.
    // The encryption is the archive's authenticity check, so this must be refused.
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('dump.sql', 'SUBSTITUTED PLAINTEXT DUMP');
    $zip->close();

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'the-password'))
        ->toThrow(RuntimeException::class, 'not encrypted');

    expect(File::exists($this->destination.'/dump.sql'))->toBeFalse();
});

it('refuses an archive that expands past the configured extraction cap', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('dump.sql', str_repeat('A', 5000));
    $zip->close();

    config()->set('notifier.restore_max_extracted_bytes', 1000);

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, ''))
        ->toThrow(RuntimeException::class, 'limit');

    expect(File::exists($this->destination.'/dump.sql'))->toBeFalse();
});

it('extracts normally when the archive is within the extraction cap', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('dump.sql', str_repeat('A', 500));
    $zip->close();

    config()->set('notifier.restore_max_extracted_bytes', 1_000_000);

    $written = app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, '');

    expect($written)->toBe(1)
        ->and(File::get($this->destination.'/dump.sql'))->toBe(str_repeat('A', 500));
});

it('still extracts a plaintext archive when NO password is configured', function () {
    // Symmetry check: the encryption gate only applies when a password is set, so
    // an install with no backup password keeps working with plain archives.
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('dump.sql', 'PLAIN DUMP');
    $zip->close();

    $written = app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, '');

    expect($written)->toBe(1)
        ->and(File::get($this->destination.'/dump.sql'))->toBe('PLAIN DUMP');
});

/**
 * Flip the first ciphertext byte of a single-entry WinZip AES archive: the local
 * header is 30 fixed bytes plus the name and extra field, followed by the 16-byte
 * AES-256 salt and the 2-byte password verifier.
 */
function corruptFirstCiphertextByte(string $zipPath): void
{
    $bytes = File::get($zipPath);
    $lengths = unpack('vname/vextra', $bytes, 26);
    $offset = 30 + $lengths['name'] + $lengths['extra'] + 18;
    $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0xFF);
    File::put($zipPath, $bytes);
}

/**
 * WinZip AES encryption with the test's own password: CTR mode with a
 * little-endian counter starting at 1, as the format defines it.
 *
 * @return array{0: string, 1: string} [salt + password verifier, ciphertext]
 */
function winzipEncrypt(string $plaintext, string $password): array
{
    $salt = random_bytes(16);
    $keys = hash_pbkdf2('sha1', $password, $salt, 1000, 66, true);
    $key = mb_substr($keys, 0, 32, '8bit');
    $ciphertext = '';

    foreach (mb_str_split($plaintext, 16, '8bit') as $block => $bytes) {
        $counter = pack('P', $block + 1).str_repeat("\0", 8);
        $ciphertext .= $bytes ^ openssl_encrypt($counter, 'aes-256-ecb', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
    }

    return [$salt.mb_substr($keys, 64, 2, '8bit'), $ciphertext];
}

/**
 * Lay out a single AES entry by hand from its packed bytes.
 */
function aesEntryArchive(string $name, string $packed, int $aeVersion, int $realMethod, int $crc, int $size): string
{
    $extra = pack('vv', 0x9901, 7).pack('v', $aeVersion).'AE'.chr(3).pack('v', $realMethod);
    $packedSize = mb_strlen($packed, '8bit');
    $nameSize = mb_strlen($name, '8bit');
    $extraSize = mb_strlen($extra, '8bit');
    $common = pack('vvvvvVVVvv', 51, 1, 99, 0, 0x21, $crc, $packedSize, $size, $nameSize, $extraSize);
    $local = "PK\x03\x04".$common.$name.$extra;
    $central = "PK\x01\x02".pack('v', 0x033F).$common.pack('vvvVV', 0, 0, 0, 0, 0).$name.$extra;

    return $local.$packed.$central
        ."PK\x05\x06".pack('vvvvVVv', 0, 0, 1, 1, mb_strlen($central, '8bit'), mb_strlen($local, '8bit') + $packedSize, 0);
}

/**
 * The packed bytes of the only entry in an archive.
 */
function packedEntryBytes(string $zipPath): string
{
    $bytes = File::get($zipPath);
    $header = unpack('Vpacked/Vsize/vname/vextra', $bytes, 18);

    return mb_substr($bytes, 30 + $header['name'] + $header['extra'], $header['packed'], '8bit');
}

it('refuses a corrupted entry with a clear error instead of a raw PHP warning', function () {
    File::put($this->source.'/dump.sql', 'SECRET DATABASE CONTENTS');
    app(PhpZipCreator::class)->create($this->source.'/dump.sql', $this->zipPath, 'pw');
    corruptFirstCiphertextByte($this->zipPath);

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw', sealVerified: true))
        ->toThrow(RuntimeException::class, 'Failed to extract "dump.sql"');
});

it('does not report success for a corrupted entry on a host that ignores warnings', function () {
    File::put($this->source.'/dump.sql', 'SECRET DATABASE CONTENTS');
    app(PhpZipCreator::class)->create($this->source.'/dump.sql', $this->zipPath, 'pw');
    corruptFirstCiphertextByte($this->zipPath);

    $previous = error_reporting(E_ALL & ~E_WARNING);

    try {
        expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw', sealVerified: true))
            ->toThrow(RuntimeException::class, 'Failed to extract "dump.sql"');
    } finally {
        error_reporting($previous);
    }
});

it('rejects an entry whose authentication code does not match', function () {
    File::put($this->source.'/dump.sql', 'SECRET DATABASE CONTENTS');
    app(PhpZipCreator::class)->create($this->source.'/dump.sql', $this->zipPath, 'pw');
    corruptFirstCiphertextByte($this->zipPath);

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw'))
        ->toThrow(RuntimeException::class, 'failed authentication');

    expect(File::exists($this->destination.'/dump.sql'))->toBeFalse();
});

it('rejects a well-formed entry with the wrong authentication code', function () {
    $body = str_repeat('INSERT INTO t VALUES (1);', 40);
    $bodySize = mb_strlen($body, '8bit');
    $data = "\x01".pack('v', $bodySize).pack('v', (~$bodySize) & 0xFFFF).$body.str_repeat("\xA5", 200_000);

    [$header, $ciphertext] = winzipEncrypt($data, 'pw');

    File::put($this->zipPath, aesEntryArchive('dump.sql', $header.$ciphertext.random_bytes(10), 1, 8, crc32($body), $bodySize));

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw'))
        ->toThrow(RuntimeException::class, 'failed authentication');

    expect(File::exists($this->destination.'/dump.sql'))->toBeFalse();
});

it('extracts genuine entries of any size when no seal has verified them', function (string $contents) {
    makeEncryptedArchive($this->zipPath, 'pw', ['a/file.bin' => $contents]);

    expect(app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw'))->toBe(1)
        ->and(hash_file('sha256', $this->destination.'/a/file.bin'))->toBe(hash('sha256', $contents));
})->with([
    'empty' => [''],
    'one byte' => ['x'],
    'compressible' => [str_repeat('INSERT INTO t VALUES (1);', 50_000)],
    'incompressible' => [random_bytes(3 * 1024 * 1024)],
]);

it('extracts an entry that declares no CRC, as 7-Zip writes them', function () {
    $contents = str_repeat('INSERT INTO t VALUES (1);', 200);
    makeEncryptedArchive($this->zipPath, 'pw', ['dump.sql' => $contents]);

    File::put($this->zipPath, aesEntryArchive('dump.sql', packedEntryBytes($this->zipPath), 2, 8, 0, mb_strlen($contents, '8bit')));

    expect(app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw'))->toBe(1)
        ->and(File::get($this->destination.'/dump.sql'))->toBe($contents);
});

it('reports a wrong password separately from a failed authentication', function () {
    makeEncryptedArchive($this->zipPath, 'right-password');

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'wrong-password'))
        ->toThrow(RuntimeException::class, 'password does not fit');
});

it('refuses encryption other than AES-256 when a password is configured', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->setPassword('pw');
    $zip->addFromString('dump.sql', 'contents');
    $zip->setEncryptionName('dump.sql', ZipArchive::EM_AES_128);
    $zip->close();

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw'))
        ->toThrow(RuntimeException::class, 'not AES-256');
});

it('writes nothing for an entry declared empty over real data', function () {
    $contents = str_repeat('INSERT INTO t VALUES (1);', 200);
    makeEncryptedArchive($this->zipPath, 'pw', ['dump.sql' => $contents]);

    File::put($this->zipPath, aesEntryArchive('dump.sql', packedEntryBytes($this->zipPath), 2, 8, 0, 0));

    // Depending on the libzip build the entry reads as empty or the copy is refused.
    try {
        app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw');

        expect(File::size($this->destination.'/dump.sql'))->toBe(0);
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Failed to extract "dump.sql"');
    }
});

it('refuses a genuine entry that declares less than it holds', function () {
    $contents = str_repeat('INSERT INTO t VALUES (1);', 200);
    makeEncryptedArchive($this->zipPath, 'pw', ['dump.sql' => $contents]);

    $crc = crc32($contents);
    File::put($this->zipPath, aesEntryArchive('dump.sql', packedEntryBytes($this->zipPath), 1, 8, $crc, mb_strlen($contents, '8bit') - 100));

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'pw'))
        ->toThrow(RuntimeException::class, 'Failed to extract "dump.sql"');
});

it('refuses control characters in entry names of an archive that is not authenticated', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString("odd\nname.txt", 'contents');
    $zip->close();

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, ''))
        ->toThrow(RuntimeException::class, 'control characters');

    expect(File::files($this->destination))->toBe([]);
});

it('stops writing an entry that inflates past its declared size', function () {
    $zip = new ZipArchive;
    $zip->open($this->zipPath, ZipArchive::CREATE);
    $zip->addFromString('dump.sql', str_repeat('A', 100_000));
    $zip->close();

    // Rewrite the declared size to 1 byte in the local header (offset 22) and in
    // the central header (offset 24), found through the end-of-directory record.
    $bytes = File::get($this->zipPath);
    $central = unpack('Voffset', $bytes, File::size($this->zipPath) - 22 + 16)['offset'];

    foreach ([22, $central + 24] as $offset) {
        foreach (mb_str_split(pack('V', 1)) as $i => $byte) {
            $bytes[$offset + $i] = $byte;
        }
    }

    File::put($this->zipPath, $bytes);

    expect(fn () => app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, ''))
        ->toThrow(RuntimeException::class, 'Failed to extract "dump.sql"');

    expect(File::size($this->destination.'/dump.sql'))->toBeLessThanOrEqual(2);
});

it('flags only 7-Zip style AES entries on libzip versions that cannot verify them', function (array $overrides, string $libzip, bool $expected) {
    $stat = [...['encryption_method' => ZipArchive::EM_AES_256, 'crc' => 0, 'size' => 24], ...$overrides];

    expect(PhpZipExtractor::isUnverifiableByLibzip($stat, $libzip))->toBe($expected);
})->with([
    'zero CRC on Ubuntu 24.04 libzip' => [[], '1.7.3', true],
    'zero CRC on the last affected libzip' => [[], '1.8.0', true],
    'exactly at the 20-byte threshold' => [['size' => 20], '1.7.3', true],
    'below the 20-byte threshold' => [['size' => 19], '1.7.3', false],
    'a real CRC, as libzip itself writes' => [['crc' => 123456], '1.7.3', false],
    'an unencrypted entry' => [['encryption_method' => ZipArchive::EM_NONE], '1.7.3', false],
    'first libzip that ignores the AE-2 CRC' => [[], '1.9.0', false],
    'current libzip' => [[], '1.12.0', false],
]);

it('reads a 7z-made archive', function () {
    File::put($this->source.'/dump.sql', 'SECRET DATABASE CONTENTS');
    app(CliZipCreator::class)->create($this->source, $this->zipPath, 'strong-password');

    expect(app(PhpZipExtractor::class)->extract($this->zipPath, $this->destination, 'strong-password'))->toBe(1)
        ->and(File::get($this->destination.'/dump.sql'))->toBe('SECRET DATABASE CONTENTS');
})->skip(! CliZipCreator::isAvailable(), '7z CLI not available in this environment');
