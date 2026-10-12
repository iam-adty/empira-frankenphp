<?php

declare(strict_types=1);

namespace Empira\FrankenPhp;

use Empira\Application;
use Empira\Http\Session;
use Empira\Module;
use Empira\Persistence\EntityManagerFactory;

/**
 * Worker mode, as an opt-in.
 *
 * A module is a Composer package of type `library` that requires
 * `empira-principle`; this one also happens to carry a runtime. That is not a
 * departure from the rule so much as the rule meeting a thing that genuinely
 * needs shipping: the worker is a *process* around the application, and a
 * `Module::register()` hook cannot be a process.
 *
 * So this package ships both halves, and an application installs it the same way
 * it installs authentication:
 *
 *     $app->module(new FrankenPhpModule());
 *
 * What `register()` does is small, because the framework already knows how to be
 * safe in a long-running process and only has to be *told* it is in one. Every
 * method called below is something {@see Application} does unconditionally; the
 * claim is that they stop being defensive and become load-bearing, and that
 * saying so somewhere is worth more than it sounds.
 *
 * **What is genuinely different under a worker**, and what this exists to record:
 *
 * - `Session::beginRequest()` / `endRequest()` are no longer tidiness. PHP
 *   sessions are process-wide, so a worker that does not reset one serves the
 *   second visitor the first visitor's `$_SESSION` — silently, with nothing in
 *   any log.
 * - `EntityManager::clear()` is the same trap in a library. An identity map that
 *   is not cleared hands request two request one's entities, and only when both
 *   happen to land on the same thread.
 * - `respond()`, never `send()`. `send()` ends the script, which takes the worker
 *   down; FrankenPHP restarts it, and the process serves exactly one request.
 *
 * Nothing here is FrankenPHP-specific except {@see self::MAX_REQUESTS_DEFAULT}
 * being a sensible number for it. Any long-running PHP process has the same
 * requirements, which is why the framework resets unconditionally rather than
 * asking.
 */
final class FrankenPhpModule implements Module
{
    /**
     * Requests a worker serves before it recycles.
     *
     * Recycling is the price of keeping a process: PHP was not built to run
     * forever, and code outside an application leaks in ways it cannot reach.
     * The cost is a little latency every so often, in exchange for a bounded
     * process and a bounded leak.
     *
     * Zero means never recycle, which is the right setting for a debugging run
     * and a bad one for anything long-lived.
     */
    public const MAX_REQUESTS_DEFAULT = 500;

    /**
     * Record that this application is running inside a worker.
     *
     * The framework's per-request resets happen whether or not anyone says so,
     * so this method changes no behaviour. It fails loudly instead, which is the
     * point: an application that loads this module under a plain php-fpm or a
     * `php -S` has a mismatch between what it believes and what is running, and
     * the mismatch is invisible until a session or an entity leaks across a
     * request boundary.
     *
     * @throws \LogicException when FrankenPHP is not the runtime in use
     */
    public function register(Application $app): void
    {
        if (! self::frankenPhpIsAvailable()) {
            throw new \LogicException(sprintf(
                'FrankenPhpModule was installed, but this PHP is not FrankenPHP: %s',
                'frankenphp_handle_request() does not exist. The module supplies the worker '
                . 'runtime and is used with its own worker.php entrypoint. Under a classic '
                . 'runtime — php-fpm, nginx, the built-in server — install nothing: this '
                . 'package is not what makes an application run, and loading it there is a '
                . 'mistake rather than a harmless extra.'
            ));
        }

        // Nothing to bind. Every hook below is already in the framework and runs
        // on every request regardless; saying so here is a claim about the
        // runtime rather than a change to it, and the failure above is the part
        // that earns the module its place.
    }

    /**
     * Is the worker runtime actually present?
     *
     * Cheaper and more reliable than inspecting a version string, and it is the
     * only question worth asking: either the function is callable or the loop in
     * {@see self::handleForever()} cannot run.
     */
    public static function frankenPhpIsAvailable(): bool
    {
        return function_exists('frankenphp_handle_request');
    }

    /**
     * Serve requests in this process, until the runtime says stop.
     *
     * The loop that used to live in an application's `public/index.php`, which
     * is the whole reason this package exists: it is runtime, and runtime does
     * not belong in an application's front controller.
     *
     * @param Application $app
     * @param int         $maxRequests requests before the process recycles; 0 never does
     */
    public static function handleForever(Application $app, int $maxRequests = self::MAX_REQUESTS_DEFAULT): void
    {
        $handler = static function () use ($app): void {
            try {
                // `respond()`, not `send()`. `send()` ends the script, which takes
                // the worker down and makes this process serve exactly one
                // request — correct, and none of the point.
                $app->respond();
            } catch (\Throwable $e) {
                // `set_exception_handler` only fires when the script ends, which
                // in a worker never happens. A genuine bug the framework chose to
                // re-throw has to be answered here, or the worker dies with it
                // and the runtime restarts straight back into the same bug.
                fwrite(STDERR, $e . "\n");

                http_response_code(500);
                echo 'Internal Server Error';
            }
        };

        for ($handled = 0; ! $maxRequests || $handled < $maxRequests; $handled++) {
            if (! frankenphp_handle_request($handler)) {
                break;
            }

            // Collected here rather than whenever the engine feels like it, so a
            // long-lived worker is not paused in the middle of somebody's
            // response.
            gc_collect_cycles();
        }
    }
}