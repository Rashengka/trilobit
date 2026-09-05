<?php

declare(strict_types=1);

namespace Trilobit\Shop\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Routing\RouteProvider;

/**
 * Where the Shop module answers.
 *
 * One route while the module is empty, and it earns its place: it is the only
 * way to tell a build without this module from a build with it by asking the
 * router. A switched-off module registers no provider, so /shop is claimed by
 * nobody and the router says so - there is no catch-all route to answer in its
 * place.
 */
final class ShopRoutes implements RouteProvider
{
    public function provide(RouteList $routes): void
    {
        $routes->addRoute('shop', 'Shop:Front:Status:default');
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
