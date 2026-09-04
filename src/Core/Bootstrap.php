<?php

declare(strict_types=1);

namespace Trilobit\Core;

use Nette\Bootstrap\Configurator;
use Nette\DI\Container;
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

        $configurator = new Configurator();
        $configurator->setDebugMode($environment->flag('TRILOBIT_DEBUG'));
        $configurator->enableTracy($root . '/var/log');
        $configurator->setTempDirectory($root . '/var/tmp');

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
