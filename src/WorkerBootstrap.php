<?php

declare(strict_types=1);

namespace Empira\FrankenPhp;

use Empira\Application;

/**
 * Finding the application, from a file that lives in two places.
 *
 * `worker.php` has to load the application's autoloader before it can do anything
 * else, and it cannot be in its own autoloader. So this class is deliberately
 * free of any dependency on the framework: it computes two paths and returns
 * strings. Nothing here can reference `Application` at runtime except as a
 * return type on {@see self::boot()}, which is only reached once the caller has
 * already loaded the autoloader.
 *
 * ## Why the working directory
 *
 * `worker.php` is either `app/vendor/iam-adty/empira-frankenphp/worker.php` — the
 * installed case, and the only one anybody ships — or a sibling checkout next to
 * `app/`. Those two layouts share nothing to be relative to, so no fixed path can
 * serve both. Searching upward from the file's own directory serves neither
 * correctly: in the sibling layout it finds *this package's* `vendor/`, and
 * loads the application's dependencies from the wrong install.
 *
 * The working directory is the one input that is the application root in every
 * documented way of running the worker, including `bin/frankenphp-worker`, which
 * `cd`s there before exec'ing anything. Two entrypoints that agree by
 * construction beat two that agree by convention.
 */
final class WorkerBootstrap
{
    /**
     * Boot the application, or explain precisely what is missing.
     *
     * A worker that dies with an uncaught "failed to open stream" or a class-not
     * found is a worker that restarts into the same failure, and FrankenPHP's
     * answer to that is a crash loop with a 502 in front of it. The messages here
     * say which file is missing and where it was looked for, because the one
     * thing an operator cannot do is guess at this.
     *
     * @param string      $appRoot   the application root, normally the working directory
     * @param string|null $autoload  explicit `vendor/autoload.php`, overriding the default
     * @param string|null $bootstrap explicit `bootstrap/app.php`, overriding the default
     *
     * @throws \RuntimeException when the autoloader or the bootstrap is missing
     */
    public static function boot(
        string $appRoot,
        ?string $autoload = null,
        ?string $bootstrap = null,
    ): Application {
        $autoload = $autoload ?? $appRoot . '/vendor/autoload.php';

        if (! is_file($autoload)) {
            throw new \RuntimeException(sprintf(
                'empira-frankenphp: no autoloader at %s. Run this from the application root, '
                . 'or set EMPIRA_AUTOLOAD to the application\'s vendor/autoload.php.',
                $autoload,
            ));
        }

        require $autoload;

        $bootstrap = $bootstrap ?? $appRoot . '/bootstrap/app.php';

        if (! is_file($bootstrap)) {
            throw new \RuntimeException(sprintf(
                'empira-frankenphp: no bootstrap at %s. Set EMPIRA_BOOTSTRAP to the file that '
                . 'returns the booted Application.',
                $bootstrap,
            ));
        }

        /** @var Application $app */
        $app = require $bootstrap;

        if (! $app instanceof Application) {
            // Worth catching: the bootstrap is a `return`, so a file that ends in
            // a statement instead of returning the application fails here rather
            // than three frames later inside the request loop.
            throw new \RuntimeException(sprintf(
                'empira-frankenphp: %s did not return an %s.',
                $bootstrap,
                Application::class,
            ));
        }

        return $app;
    }
}