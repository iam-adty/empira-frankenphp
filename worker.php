<?php

declare(strict_types=1);

/**
 * The worker entrypoint. This file is a runtime, not part of an application.
 *
 * Point FrankenPHP's worker mode at it:
 *
 *     frankenphp php-server run -f vendor/iam-adty/empira-frankenphp/worker.php
 *
 * A classic runtime — php-fpm, nginx, the built-in server — does not use this
 * file at all. An application's `public/index.php` boots and calls `send()`,
 * which is correct when the process has one request and nothing after it. The
 * loop below exists because a worker does not, and putting it in an application
 * meant every application carried a FrankenPHP dependency it mostly did not run
 * on.
 *
 * The application's own bootstrap still decides what it is: `EMPIRA_BOOTSTRAP`
 * points at `bootstrap/app.php` if the framework's console cannot find it by
 * convention.
 */

use Empira\Application;
use Empira\FrankenPhp\FrankenPhpModule;

require dirname(__DIR__, 2) . '/app/vendor/autoload.php';

$bootstrap = getenv('EMPIRA_BOOTSTRAP') ?: (getcwd() ?: '.') . '/bootstrap/app.php';

if (! is_file($bootstrap)) {
    fwrite(STDERR, sprintf("empira-frankenphp: no bootstrap at %s\n", $bootstrap));
    exit(1);
}

/** @var Application $app */
$app = require $bootstrap;

FrankenPhpModule::handleForever($app, (int) (getenv('MAX_REQUESTS') ?: FrankenPhpModule::MAX_REQUESTS_DEFAULT));