<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "uses()" function to bind a different classes or traits.
|
*/

uses(Devuni\Notifier\Tests\TestCase::class)->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the amount of code you need to type in your tests.
|
*/

function something()
{
    // ..
}

/**
 * Write a real AES-256 archive, as PhpZipCreator would.
 *
 * @param  array<string, string>  $entries  Entry name => contents.
 */
function makeEncryptedArchive(string $path, string $password, array $entries = ['dump.sql' => 'SELECT 1;']): void
{
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->setPassword($password);

    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
        $zip->setEncryptionName($name, ZipArchive::EM_AES_256);
    }

    $zip->close();

    clearstatcache(true, $path);
}
