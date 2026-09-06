<?php

declare(strict_types=1);

namespace Trilobit\Cms\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Routing\RouteProvider;

/**
 * Where the Cms module answers.
 *
 * **Its pages are not here, and that is the point.** A page answers at
 * whatever address the register of public addresses holds for it, so /about-us
 * is claimed by Core's catch-all and drawn by this module's page presenter -
 * see Trilobit\Cms\Content\CmsContentTypes. A route of its own would put the
 * module's name back into the URL, which is the thing decision R8 took out of
 * it, and it would claim a beginning of the address space that no other module
 * could then use.
 *
 * What is here is what a static route is for: the module's own status page,
 * which is the only way to tell a build with this module from a build without
 * it by asking the router, and its section of the administration - the one
 * beginning every module may add pages underneath, because it is Core's and
 * Core is in every build.
 */
final class CmsRoutes implements RouteProvider
{
    /** Where this module's section of the administration begins. */
    public const string ADMIN_PATH = 'admin/cms';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute('cms', 'Cms:Front:Status:default');

        // The form for a new page comes before the one that takes an
        // identifier: the other way round, `add` would be read as the
        // identifier of a page and refused for not being a number.
        $routes->addRoute(self::ADMIN_PATH . '/pages', 'Cms:Admin:Page:default');
        $routes->addRoute(self::ADMIN_PATH . '/pages/add', 'Cms:Admin:Page:add');
        $routes->addRoute(self::ADMIN_PATH . '/pages/<id>', 'Cms:Admin:Page:edit');

        $routes->addRoute(self::ADMIN_PATH . '/menus', 'Cms:Admin:Menu:default');
        $routes->addRoute(self::ADMIN_PATH . '/menus/add', 'Cms:Admin:Menu:add');
        $routes->addRoute(self::ADMIN_PATH . '/menus/<id>', 'Cms:Admin:Menu:edit');
    }

    /**
     * Nothing, and it is not an oversight: every declared module's own name is
     * reserved by Core whether the module is switched on or off, and so is the
     * administration, so both beginnings above are covered without this saying
     * so a second time. A route this module adds somewhere else in the address
     * space belongs here.
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        return [];
    }
}
