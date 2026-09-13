<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\DI\Container;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Security\PermissionStructure;
use Trilobit\Tests\Boot;

/**
 * The widest build this checkout can produce - every declared module switched
 * on and the style guide present - for the rules that have to hold whatever a
 * build is made of.
 *
 * A module brings things of its own that Core never names: routes and their
 * reserved beginnings, resources a permission question may be about. A rule
 * checked against the build the configuration happens to describe would miss
 * whatever a switched-off module brings, so these rules ask this one.
 *
 * It is built once per process; it is a question about the configuration, and
 * the configuration does not change while the suite runs.
 */
final class WidestBuild
{
    private static ?Container $container = null;

    public static function container(): Container
    {
        $root = Bootstrap::rootDirectory();

        return self::$container ??= Boot::container(
            ModuleList::of(
                array_fill_keys(ModuleList::fromNeon($root . '/config/modules.neon', $root)->names(), true),
                $root,
            ),
            styleguide: true,
        );
    }

    /** Core's resources and every declared module's. */
    public static function permissionStructure(): PermissionStructure
    {
        return self::container()->getByType(PermissionStructure::class);
    }
}
