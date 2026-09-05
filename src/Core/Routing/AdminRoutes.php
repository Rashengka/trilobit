<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Where the administration answers.
 *
 * Every path is written out rather than produced by a mask with <presenter> and
 * <action> in it, for the reason the router has no catch-all route either: a
 * mask claims every path under /admin, including the ones belonging to pages
 * that do not exist, and answers for them with a framework error instead of
 * leaving them unclaimed. Written out, a module adding an administration
 * section adds a route somebody can read.
 *
 * The three here are Core's own and are in every build, because Core cannot be
 * switched off - which is also why the administration is the one place a module
 * may assume exists.
 */
final class AdminRoutes implements RouteProvider
{
    public const string PATH = 'admin';

    public const string SIGN_IN_PATH = 'admin/sign-in';

    public const string SIGN_OUT_PATH = 'admin/sign-out';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::SIGN_IN_PATH, 'Core:Admin:Sign:in');
        $routes->addRoute(self::SIGN_OUT_PATH, 'Core:Admin:Sign:out');
        $routes->addRoute(self::PATH, 'Core:Admin:Dashboard:default');
    }

    /**
     * Declared here as well as in Trilobit\Core\Content\ReservedSegments,
     * which holds it as one of Core's own: the constant is the one place the
     * path is written, so the two cannot disagree.
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        return [self::PATH];
    }
}
