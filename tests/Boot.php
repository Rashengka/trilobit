<?php

declare(strict_types=1);

namespace Trilobit\Tests;

use Doctrine\DBAL\Connection;
use Nette\DI\Container;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Tests\Runner\KeepingUnitTestsAwayFromTheDatabase;
use WeakReference;

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
 *
 * The second thing a boot leaves behind is a database connection, and that one
 * is not given back here but by Trilobit\Tests\Runner\ClosingConnections after
 * every test - see letGoOfEveryConnection() for why it is not left to each
 * test to remember.
 *
 * That connection is also why a unit test is not given a container at all: it
 * would be handed a live one for the database on the machine the suite happens
 * to be running on. See
 * Trilobit\Tests\Runner\KeepingUnitTestsAwayFromTheDatabase.
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
     * Every build this class has handed out and that somebody still holds.
     *
     * Weak on purpose: a reference kept here would be the one thing stopping a
     * finished test's container from being collected, so the register meant to
     * save connections would spend memory instead. What is dead is dropped on
     * the next pass.
     *
     * @var list<WeakReference<Container>>
     */
    private static array $built = [];

    /**
     * @param bool $styleguide whether this build has the style guide page.
     *     Stated rather than left to the default, because the default is
     *     %debugMode%: on where there is a .env and off in a fresh clone, so a
     *     suite taking it would assert one thing on a developer's machine and
     *     another in CI.
     * @param array<string, mixed> $config anything else this build is to be
     *     given - a service a module would have registered, say. It is the one
     *     way a suite can stand in for a module that has not been written yet
     *     without a directory under src/ pretending to be one.
     */
    public static function container(?ModuleList $modules = null, bool $styleguide = false, array $config = []): Container
    {
        // The second door into a database, and the one that opens without
        // saying so: a built container carries a connection pointed at whatever
        // TRILOBIT_DB_NAME happens to be, which on a developer's machine is the
        // database they are working in. See the guard for why a unit test is
        // turned away here rather than at the socket.
        KeepingUnitTestsAwayFromTheDatabase::refuse('a built application container');

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

        if ($config !== []) {
            $configurator->addConfig($config);
        }

        if (!self::$handlersTaken) {
            self::$handlersTaken = true;
            restore_error_handler();
            restore_exception_handler();
        }

        $container = $configurator->createContainer();
        self::$built[] = WeakReference::create($container);

        return $container;
    }

    /** The build the application is in when no optional module is switched on. */
    public static function coreAlone(): Container
    {
        return self::container(ModuleList::of([], Bootstrap::rootDirectory()));
    }

    /**
     * Hands the server back every connection the builds of this process have
     * opened and not closed.
     *
     * A container holds its connection until the process ends, and the process
     * here outlives every test in it, so a suite that builds a container per
     * case walks towards the server's max_connections and arrives there without
     * saying so - the failure reads as one broken test and a handful of skips.
     *
     * It is not left to each test to close what it opened, and that is the
     * point rather than a convenience: "remember to do it everywhere" includes
     * the tests nobody has written yet, and the first one that forgets brings
     * the ceiling back. The register is kept here and emptied from outside, so
     * a test cannot opt out of it by being new.
     *
     * Closing is not disposing. DBAL opens a fresh connection the next time it
     * is asked for one, so a build deliberately kept across cases - see
     * Trilobit\Tests\Combination\Build - keeps working and simply reconnects.
     * What it costs is an open transaction: one left running at the end of a
     * test is rolled back by the server rather than carried into the next case,
     * which is what a test spanning cases would have been relying on. Nothing
     * here does that, and a test that wants to should say so out loud.
     *
     * A connection is only closed if the build ever made one. Asking the
     * container for a service that has not been created yet would open a
     * connection in order to close it.
     */
    public static function letGoOfEveryConnection(): void
    {
        $stillHeld = [];
        foreach (self::$built as $reference) {
            $container = $reference->get();
            if ($container === null) {
                continue;
            }

            $stillHeld[] = $reference;
            foreach ($container->findByType(Connection::class) as $name) {
                $connection = $container->isCreated($name) ? $container->getService($name) : null;
                if ($connection instanceof Connection) {
                    $connection->close();
                }
            }
        }

        self::$built = $stillHeld;
    }
}
