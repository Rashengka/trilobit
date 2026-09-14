<?php

declare(strict_types=1);

namespace Trilobit\Shop\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Routing\RouteProvider;

/**
 * Where the Shop module answers.
 *
 * /shop is the way to tell a build without this module from a build with it
 * by asking the router: a switched-off module registers no provider, so the
 * address is claimed by nobody and the router says so - there is no catch-all
 * route to answer in its place. The same holds for the catalogue under the
 * administration.
 *
 * A product answers at no route of this module: its addresses are rows of
 * Core's register, one per category it is filed in (decision R12).
 */
final class ShopRoutes implements RouteProvider
{
    /** Where this module's section of the administration begins. */
    public const string ADMIN_PATH = 'admin/shop';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute('shop', 'Shop:Front:Status:default');

        // The form for a new product comes before the one that takes an
        // identifier: the other way round, `add` would be read as the
        // identifier of a product and refused for not being a number.
        $routes->addRoute(self::ADMIN_PATH . '/products', 'Shop:Admin:Product:default');
        $routes->addRoute(self::ADMIN_PATH . '/products/add', 'Shop:Admin:Product:add');
        $routes->addRoute(self::ADMIN_PATH . '/products/<id>', 'Shop:Admin:Product:edit');
    }

    /**
     * Nothing, and it is not an oversight: every declared module's own name
     * is reserved by Core whether the module is switched on or off, so the
     * route above is covered without this saying so a second time. A route
     * this module adds somewhere else in the address space belongs here.
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        return [];
    }
}
