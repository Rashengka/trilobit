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

        $files = self::configurationFiles($modules);

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
            // What the configuration files say, as one value.
            //
            // Outside debug mode the framework does not look at whether a
            // configuration file has changed since the container was compiled;
            // it hands back the cached container, which is right in production
            // and wrong on a working copy, where it means a change to a NEON
            // file has no effect and no error either. The compiled container is
            // cached by its static parameters, so putting the contents in one
            // makes the cache key say what it is a cache of.
            'configHash' => self::configurationHash($files),
        ]);
        $configurator->addDynamicParameters([
            'env' => $environment->resolved(),
        ]);

        // A module brings its own configuration with it. A switched-off module
        // contributes no file, which is why it ends up with no services, no
        // presenter mapping, no entity mapping and no migration directory
        // without anything having to know its name.
        foreach ($files as $file) {
            $configurator->addConfig($file);
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

    /**
     * The configuration files a build made of $modules is assembled from, in
     * the order they are loaded: the shared file, the application's own, one
     * per enabled module, and last the machine-local override so that it wins
     * over everything else.
     *
     * A module that is switched off contributes nothing here, which is what
     * leaves it without services, without a presenter mapping, without an
     * entity mapping and without a migration directory - none of which
     * anything had to know its name to arrange.
     *
     * @return list<string>
     */
    public static function configurationFiles(ModuleList $modules): array
    {
        $root = $modules->rootDirectory();

        $files = [$root . '/config/common.neon', $root . '/config/services.neon'];
        foreach ($modules->enabled() as $module) {
            $files[] = $module->configFile();
        }

        $local = $root . '/config/local.neon';
        if (is_file($local)) {
            $files[] = $local;
        }

        return $files;
    }

    /**
     * What those files say, as one value.
     *
     * Their order is part of it, because order decides which file wins a
     * repeated key: the same files read in a different sequence are a
     * different configuration.
     *
     * @param list<string> $files
     */
    public static function configurationHash(array $files): string
    {
        $contents = '';
        foreach ($files as $file) {
            $contents .= $file . "\0" . FileSystem::read($file) . "\0";
        }

        return hash('xxh128', $contents);
    }
}
