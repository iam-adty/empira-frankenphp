<?php

declare(strict_types=1);

namespace Empira\FrankenPhp\Tests;

use Empira\Application;
use Empira\FrankenPhp\FrankenPhpModule;
use Empira\Module;
use PHPUnit\Framework\TestCase;

/**
 * The module, on a runtime that is not the one it is for.
 *
 * Every test here runs under a host PHP, which is deliberately the wrong runtime
 * for this package — so the suite proves the failure it exists to prevent, and
 * the runtime-detection path is covered by the one thing that can be tested
 * without FrankenPHP installed.
 */
final class FrankenPhpModuleTest extends TestCase
{
    public function testItIsAModuleLikeAnyOther(): void
    {
        self::assertInstanceOf(Module::class, new FrankenPhpModule());
    }

    /**
     * The check that earns the module its place.
     *
     * An application that installs this under php-fpm has a mismatch between
     * what it believes and what is running. Nothing fails — the framework resets
     * its per-request state whether or not anyone asks — so the mismatch is
     * invisible until a session or an entity leaks across a request boundary and
     * the second visitor is greeted as the first.
     *
     * Refusing at boot makes that mismatch a message instead.
     */
    public function testInstallingItUnderAClassicRuntimeSaysSoRatherThanPretending(): void
    {
        // This suite runs on a host PHP, which is exactly the wrong runtime —
        // so the assertion is about the failure mode, and it holds without
        // FrankenPHP ever being installed.
        self::assertFalse(FrankenPhpModule::frankenPhpIsAvailable());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('this PHP is not FrankenPHP');

        (new FrankenPhpModule())->register(new Application('a-secret-long-enough-for-testing'));
    }

    /**
     * The message has to say what to do instead, or it is only half a refusal.
     */
    public function testTheRefusalSaysWhatToDoInstead(): void
    {
        $message = '';

        try {
            (new FrankenPhpModule())->register(new Application('a-secret-long-enough-for-testing'));
        } catch (\LogicException $e) {
            $message = $e->getMessage();
        }

        self::assertStringContainsString('php-fpm', $message);
        self::assertStringContainsString('not what makes an application run', $message);
    }

    /**
     * Detection asks whether the function exists rather than what a version
     * string says, because the function is the only thing the loop actually
     * calls.
     */
    public function testDetectionIsTheCallableFunctionNotAVersionString(): void
    {
        self::assertSame(
            function_exists('frankenphp_handle_request'),
            FrankenPhpModule::frankenPhpIsAvailable()
        );
    }

    /**
     * The default is a bound, not "forever".
     *
     * PHP was not built to run forever and code outside an application leaks in
     * ways it cannot reach, so a worker with no limit is a worker that eventually
     * holds every request it ever served.
     */
    public function testTheDefaultRequestLimitIsBoundedAndNonZero(): void
    {
        self::assertGreaterThan(0, FrankenPhpModule::MAX_REQUESTS_DEFAULT);
    }

    /**
     * The loop calls a function that does not exist here, so it cannot run. This
     * asserts the *shape* of the guard rather than pretending to execute it.
     */
    public function testTheLoopRequiresTheRuntime(): void
    {
        $method = new \ReflectionMethod(FrankenPhpModule::class, 'handleForever');

        self::assertTrue($method->isStatic());
        self::assertSame(2, $method->getNumberOfParameters());
        self::assertSame(
            FrankenPhpModule::MAX_REQUESTS_DEFAULT,
            $method->getParameters()[1]->getDefaultValue()
        );
    }
}