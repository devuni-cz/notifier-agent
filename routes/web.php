<?php

declare(strict_types=1);

use Devuni\Notifier\Controllers\NotifierSendBackupController;
use Devuni\Notifier\Middleware\VerifyNotifierTokenMiddleware;
use Illuminate\Support\Facades\Route;

$prefix = config('notifier.route_prefix', 'api/notifier');

// Throttling must run BEFORE token verification so that failed token
// attempts are rate-limited too (otherwise the token could be brute-forced
// at an unlimited rate).
Route::post("{$prefix}/backup", NotifierSendBackupController::class)
    ->middleware([
        'throttle:10,60',
        VerifyNotifierTokenMiddleware::class,
    ]);
