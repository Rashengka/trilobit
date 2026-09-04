<?php

declare(strict_types=1);

namespace Trilobit\Core;

use Nette\Bootstrap\Configurator;
use Nette\DI\Compiler;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Trilobit\Core\Config\Environment;
use Trilobit\Core\Module\ModuleList;

/**
 * Turns a checkout into a compiled container.
 *
 * Everything that differs between two deployments of the same code arrives
 * through the environment file, and everything that differs between two builds
 * of the application arrives through the configuration files. Nothing is
 * decided by detection: debug mode is a variable rather than a check on the
 * visitor's address, because an address check is unreliable in production and
 * would mean an address written down in a public repository.
 *
 * The one thing decided here rather than in configuration is which modules the
 * build contains, and only because it has to be known before the configuration
 * can be assembled: an enabled module contributes a file to load and a
 * compiler extension to run, and a disabled one contributes neither.
 */
final class Bootstrap
{
    public static function boot(?ModuleList $modules = null): Container
    {
        return self::configurator($modules)->createContainer();
    }

    /**
     * @param ModuleList|null $modules which modules to build with; by default
     *     the ones config/modules.neon declares. A caller passing its own list
     *     gets a different container - the list is part of the cache key - so
     *     that a suite can compile one build per combination without them
     *     overwriting each other.
     */
    public static function configurator(?ModuleList $modules = null): Configurator
    {
        $root = self::rootDirectory();
        $modules ??= ModuleList::fromNeon($root . '/config/modules.neon', $root);
        $environment = Environment::load($root . '/.env');

        // var/ holds only generated files, so it is not in the repository and a
        // fresh clone does not have it. Creating it here rather than asking for
        // a mkdir in the installation instructions is the difference between an
        // application that starts and one that starts once you have read a page
        // of documentation.
        $logDirectory = $root . '/var/log';
        $tempDirectory = $root . '/var/tmp';
        FileSystem::createDir($logDirectory);
        FileSystem::createDir($tempDirectory);

        $configurator = new Configurator();
        $configurator->setDebugMode($environment->flag('TRILOBIT_DEBUG'));
        $configurator->enableTracy($logDirectory);
        $configurator->setTempDirectory($tempDirectory);

        $configurator->addStaticParameters([
            'rootDir' => $root,
            'wwwDir' => $root . '/www',
            // Where the framework looks for presenters. It would otherwise be
            // guessed from the file that constructed the configurator, which
            // happens to be right and would stop being right the moment this
            // class moved. A module adds its own directory from its own
            // configuration file, so a switched-off module's presenters are
            // never scanned and never become services.
            'appDir' => $root . '/src/Core',
            // A static parameter rather than a dynamic one, because the
            // compiled container is cached by its static parameters: two
            // builds that differ only in which modules are on have to be two
            // cached containers, not one.
            'modules' => $modules->all(),
        ]);
        $configurator->addDynamicParameters([
            'env' => $environment->resolved(),
        ]);

        $configurator->addConfig($root . '/config/common.neon');
        $configurator->addConfig($root . '/config/services.neon');

        // A module brings its own configuration with it. A switched-off module
        // contributes no file, which is why it ends up with no services and no
        // presenter mapping - and, once there are entities, no mapping and no
        // migration directory either - without anything having to know its name.
        foreach ($modules->enabled() as $module) {
            $configurator->addConfig($module->configFile());
        }

        // Last, so that a machine-local override wins over everything else.
        $local = $root . '/config/local.neon';
        if (is_file($local)) {
            $configurator->addConfig($local);
        }

        // onCompile is the documented point at which an extension can be added
        // to a compilation that is already under way, which is what registering
        // extensions decided at run time needs. Doing it from inside another
        // extension's loadConfiguration() would be shorter, and is not known to
        // work; this does.
        $configurator->onCompile[] = static function (Configurator $configurator, Compiler $compiler) use ($modules): void {
            foreach ($modules->enabled() as $module) {
                $compiler->addExtension($module->name, $module->createExtension());
            }
        };

        return $configurator;
    }

    public static function rootDirectory(): string
    {
        return dirname(__DIR__, 2);
    }
}
