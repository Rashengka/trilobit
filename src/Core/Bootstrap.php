<?php

declare(strict_types=1);

namespace Trilobit\Core;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
use Nette\Utils\FileSystem;
use Trilobit\Core\Config\Environment;

/**
 * Turns a checkout into a compiled container.
 *
 * Everything that differs between two deployments of the same code arrives
 * through the environment file, and everything that differs between two builds
 * of the application arrives through the configuration files. Nothing is
 * decided by detection: debug mode is a variable rather than a check on the
 * visitor's address, because an address check is unreliable in production and
 * would mean an address written down in a public repository.
 */
final class Bootstrap
{
    public static function boot(): Container
    {
        return self::configurator()->createContainer();
    }

    public static function configurator(): Configurator
    {
        $root = self::rootDirectory();
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
        ]);
        $configurator->addDynamicParameters([
            'env' => $environment->resolved(),
        ]);

        $configurator->addConfig($root . '/config/common.neon');
        $configurator->addConfig($root . '/config/services.neon');

        $local = $root . '/config/local.neon';
        if (is_file($local)) {
            $configurator->addConfig($local);
        }

        return $configurator;
    }

    public static function rootDirectory(): string
    {
        return dirname(__DIR__, 2);
    }
}
