<?php

declare(strict_types=1);

use Devuni\Notifier\Commands\NotifierInstallCommand;

/*
|--------------------------------------------------------------------------
| notifier:install must reject weak backup ZIP passwords.
|--------------------------------------------------------------------------
|
| The backup password encrypts the whole DB + storage archive, so a short or
| low-entropy value is crackable offline once the archive leaks.
*/

it('rejects a backup password shorter than the minimum length', function () {
    expect(NotifierInstallCommand::backupPasswordError('short123'))->not->toBeNull();
});

it('rejects a long but low-entropy backup password', function () {
    expect(NotifierInstallCommand::backupPasswordError(str_repeat('a', 20)))->not->toBeNull()
        ->and(NotifierInstallCommand::backupPasswordError(str_repeat('1234', 5)))->not->toBeNull();
});

it('rejects the weak example from the security review', function () {
    expect(NotifierInstallCommand::backupPasswordError('d_secret123'))->not->toBeNull();
});

it('accepts a sufficiently strong backup password', function () {
    expect(NotifierInstallCommand::backupPasswordError('Tr0ub4dour-Horse-Staple!'))->toBeNull()
        ->and(NotifierInstallCommand::backupPasswordError(bin2hex(random_bytes(24))))->toBeNull();
});
