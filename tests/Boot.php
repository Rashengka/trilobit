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
    /**
     * A manifest the real build produces, without running the real build.
     *
     * A suite that renders a real page renders through ViteMapper, which in
     * production reads www/build/.vite/manifest.json - a file `npm run
     * build` writes and `composer test` must not need, because it runs
     * without Node and CI never runs npm. Pointing every test-built
     * container at this fixture instead, rather than teaching ViteMapper to
     * tolerate a missing manifest, keeps a real missing manifest a real
     * error in production - see vendor/nette/assets' ViteMapper::
     * readChunks(). tests/Combination/NoRealBuildRequiredTest asserts this
     * holds even when a real www/build happens to sit on the machine
     * `composer test` runs on.
     */
    private const string ASSET_MANIFEST_FIXTURE = __DIR__ . '/Fixtures/vite-manifest.json';

    private static bool $handlersTaken = false;

    /**
     * @param bool $styleguide whether this build has the style guide page.
     *     Stated rather than left to the default, because the default is
     *     %debugMode%: on where there is a .env and off in a fresh clone, so a
     *     suite taking it would assert one thing on a developer's machine and
     *     another in CI.
     */
    public static function container(?ModuleList $modules = null, bool $styleguide = false): Container
    {
        $configurator = Bootstrap::configurator($modules);
        $configurator->addConfig([
            'parameters' => [
                'trilobit' => [
                    'styleguide' => $styleguide,
                ],
            ],
            'assets' => [
                'mapping' => [
                    'vite' => [
                        'manifest' => self::ASSET_MANIFEST_FIXTURE,
                    ],
                ],
            ],
        ]);

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
