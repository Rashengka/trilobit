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
 * The ones here are Core's own and are in every build, because Core cannot be
 * switched off - which is also why the administration is the one place a module
 * may assume exists.
 *
 * Two of them are the installation's own section, and it begins one segment
 * under /admin rather than somewhere else entirely. The administration has one
 * root and what is under it is a section, whether the section belongs to a
 * module or, as this one does, to the installation itself; a second root would
 * be a second thing to reserve and a second shape for a link to have.
 */
final class AdminRoutes implements RouteProvider
{
    public const string PATH = 'admin';

    /**
     * Coming in is the administration's own, and going out is not: it moved to
     * Trilobit\Core\Routing\SessionRoutes, because one session ended by one act
     * cannot have an address that says which part of the application the person
     * ending it happened to be in.
     */
    public const string SIGN_IN_PATH = 'admin/sign-in';

    /** Where the section of the installation's own administrator begins. */
    public const string INSTALLATION_PATH = 'admin/installation';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::SIGN_IN_PATH, 'Core:Admin:Sign:in');
        $routes->addRoute(self::INSTALLATION_PATH, 'Core:Installation:Signpost:default');
        $routes->addRoute(self::INSTALLATION_PATH . '/businesses', 'Core:Installation:Businesses:default');
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
