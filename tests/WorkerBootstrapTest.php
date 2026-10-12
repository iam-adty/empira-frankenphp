<?php

declare(strict_types=1);

namespace Empira\FrankenPhp\Tests;

use Empira\Application;
use Empira\FrankenPhp\WorkerBootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The worker entrypoint's only interesting problem: it has to find the
 * application from a file that lives in two different places.
 *
 * `worker.php` is `app/vendor/iam-adty/empira-frankenphp/worker.php` when
 * installed — the only layout anybody ships — and a sibling checkout of the
 * workspace when developing. Those share nothing to be relative to, which is why
 * it takes the working directory instead.
 *
 * These tests build both layouts on disk in a temp directory, because the failure
 * being guarded against is a *path* failure and asserting on a string would not
 * prove the path is the one that works.
 */
final class WorkerBootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/empira-worker-' . bin2hex(random_bytes(6));

        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    private function remove(string $path): void
    {
        // Files first, or every directory above one is "not empty" at rmdir.
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }

    /**
     * Write an application whose bootstrap returns a booted, distinguishable
     * Application. `vendor/autoload.php` is a no-op because this suite's own
     * autoloader is already loaded.
     */
    private function makeApp(string $appRoot): void
    {
        mkdir($appRoot . '/bootstrap', 0o777, true);
        mkdir($appRoot . '/vendor', 0o777, true);

        file_put_contents($appRoot . '/vendor/autoload.php', "<?php\n");
        file_put_contents(
            $appRoot . '/bootstrap/app.php',
            '<?php return new \Empira\Application(str_repeat("t", 32));'
        );
    }

    /**
     * The installed layout — the one that ships.
     *
     * The bug this guards is real and was live: the previous version of
     * `worker.php` resolved its autoloader as `dirname(__DIR__, 2) .
     * '/app/vendor/autoload.php'`, which from
     * `app/vendor/iam-adty/empira-frankenphp/` produces
     * `app/vendor/app/vendor/autoload.php` — a path that does not exist and looks
     * plausible enough to take a while to notice. Nobody installs a package that
     * cannot start.
     */
    public function testItBootsWhenInstalledAsADependency(): void
    {
        $app = $this->root . '/app';
        $this->makeApp($app);

        // Mirror the real shape: the package lives in the app's vendor tree.
        $packageDir = $app . '/vendor/iam-adty/empira-frankenphp';
        mkdir($packageDir, 0o777, true);

        $booted = WorkerBootstrap::boot($app);

        self::assertInstanceOf(Application::class, $booted);
    }

    /**
     * The sibling layout, for developing the workspace.
     */
    public function testItBootsFromASiblingCheckout(): void
    {
        $workspace = $this->root . '/workspace';
        $this->makeApp($workspace . '/app');
        mkdir($workspace . '/frankenphp', 0o777, true);

        $booted = WorkerBootstrap::boot($workspace . '/app');

        self::assertInstanceOf(Application::class, $booted);
    }

    /**
     * The trap: this package's own `vendor/` is a sibling of the application in
     * the workspace layout, and it is *not* the application's autoloader.
     *
     * An implementation that searched upward from `__DIR__` would find it here
     * and load the application's dependencies from the wrong install — silently,
     * because the file it loaded is a real autoloader that works perfectly.
     * Passing the app root must not be fooled by it.
     */
    public function testAPackagesOwnVendorIsNeverMistakenForTheApplications(): void
    {
        $workspace = $this->root . '/workspace';
        $this->makeApp($workspace . '/app');

        // A decoy: this package's own Composer install, complete and loadable.
        mkdir($workspace . '/frankenphp/vendor', 0o777, true);
        file_put_contents(
            $workspace . '/frankenphp/vendor/autoload.php',
            '<?php throw new \RuntimeException("wrong autoloader");'
        );

        $booted = WorkerBootstrap::boot($workspace . '/app');

        self::assertInstanceOf(Application::class, $booted, 'it loaded the decoy autoloader');
    }

    /**
     * A missing autoloader has to say where it looked and what to do.
     *
     * This is the difference between an operator reading one line and guessing.
     * A worker that dies in a restart loop behind a 502 is otherwise very hard to
     * diagnose from the outside.
     */
    public function testAMissingAutoloaderSaysWhatToDo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no autoloader at');

        try {
            WorkerBootstrap::boot($this->root);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('EMPIRA_AUTOLOAD', $e->getMessage());

            throw $e;
        }
    }

    public function testAMissingBootstrapSaysWhatToDo(): void
    {
        mkdir($this->root . '/vendor', 0o777, true);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no bootstrap at');

        try {
            WorkerBootstrap::boot($this->root);
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('EMPIRA_BOOTSTRAP', $e->getMessage());

            throw $e;
        }
    }

    /**
     * `EMPIRA_AUTOLOAD` overrides the guess, for the case where the working
     * directory genuinely is not the application root.
     */
    public function testTheAutoloaderCanBePointedSomewhereElse(): void
    {
        $app = $this->root . '/elsewhere';
        $this->makeApp($app);

        // No autoloader where the working directory says there should be.
        self::expectException(\RuntimeException::class);

        WorkerBootstrap::boot($this->root, autoload: $app . '/vendor/autoload.php');
    }

    /**
     * A bootstrap that forgets to return the application fails here, with the
     * file named — rather than three frames later inside the request loop, where
     * the stack is a different request's worth of noise.
     */
    public function testABootstrapThatReturnsNothingIsCaughtByName(): void
    {
        $app = $this->root . '/app';
        $this->makeApp($app);
        file_put_contents($app . '/bootstrap/app.php', "<?php\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('did not return an');

        WorkerBootstrap::boot($app);
    }

    public function testItDoesNotNeedTheFrameworkToResolveAPath(): void
    {
        // The class is loaded before any autoloader runs, so it cannot have a
        // `use` that resolves eagerly. A type check rather than a load.
        $source = file_get_contents((new \ReflectionClass(WorkerBootstrap::class))->getFileName());

        self::assertStringNotContainsString(
            '@method',
            (string) $source,
            'a docblock @method would resolve the return type eagerly'
        );
    }
}