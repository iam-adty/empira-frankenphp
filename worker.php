<?php

declare(strict_types=1);

/**
 * The worker entrypoint. This file is a runtime, not part of an application.
 *
 * Point FrankenPHP's worker mode at it, **from the application root**:
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
 * ## Why the paths are resolved from the working directory
 *
 * This file has two possible locations, and the obvious way to find the
 * application's autoloader — walk up from `__DIR__` — gets it wrong in one of
 * them.
 *
 * Installed, which is how anyone actually uses it:
 *
 *     /srv/app/vendor/iam-adty/empira-frankenphp/worker.php   → /srv/app/vendor
 *     /srv/app/vendor/autoload.php                            ✓
 *
 * A sibling checkout, which is how a workspace is developed:
 *
 *     /w/empira/frankenphp/worker.php                         → /w/empira
 *     /w/empira/app/vendor/autoload.php                       ✓
 *
 * …but the sibling case is the only one a fixed relative path can serve, because
 * the two layouts have nothing in common to be relative *to*. And searching upward
 * is worse than either: from the sibling checkout it finds
 * `/w/empira/frankenphp/vendor/autoload.php` — *this package's own* Composer
 * dependencies — and loads them instead of the application's. The failure is
 * silent. The application would boot against a framework and an autoloader it was
 * never installed with.
 *
 * So: the working directory, which is the application root in every documented
 * way of running this, with `EMPIRA_AUTOLOAD` for anything that disagrees.
 * `bin/frankenphp-worker` resolves the same way and `cd`s there first, so the two
 * agree by construction rather than by convention.
 */

use Empira\FrankenPhp\FrankenPhpModule;
use Empira\FrankenPhp\WorkerBootstrap;

/**
 * @var \Empira\Application $app
 */
$app = WorkerBootstrap::boot(
    getcwd() ?: '.',
    autoload: getenv('EMPIRA_AUTOLOAD') ?: null,
    bootstrap: getenv('EMPIRA_BOOTSTRAP') ?: null,
);

$maxRequests = (int) (getenv('MAX_REQUESTS') ?: FrankenPhpModule::MAX_REQUESTS_DEFAULT);

FrankenPhpModule::handleForever($app, $maxRequests);