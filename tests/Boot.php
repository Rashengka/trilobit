<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Nette\DI\Container;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;

/**
 * Starting the application from inside a test runner.
 *
 * There is one thing to get right and it is easy to get wrong. Booting enables
 * Tracy, which installs a global error handler and a global exception handler
 * - right for the application, and inside a runner a way to swallow whatever
 * the next case was trying to observe. So the suite hands them back.
 *
 * The subtlety is that Tracy installs them once per process and does nothing on
 * every boot after that, while a suite compiling one container per combination
 * boots many times. Restoring after each of those would pop the runner's own
 * handlers instead, which the runner notices and reports as a risky test. One
 * restore, on the boot that actually took them, is the whole of it.
 */
final class Boot
{
    private static bool $handlersTaken = false;

    public static function container(?ModuleList $modules = null): Container
    {
        $configurator = Bootstrap::configurator($modules);

        if (!self::$handlersTaken) {
            self::$handlersTaken = true;
            restore_error_handler();
            restore_exception_handler();
        }

        return $configurator->createContainer();
    }

    /** The build the application is in when no optional module is switched on. */
    public static function coreAlone(): Container
    {
        return self::container(ModuleList::of([], Bootstrap::rootDirectory()));
    }
}
