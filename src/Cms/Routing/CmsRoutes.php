<?php

declare(strict_types=1);

namespace Trilobit\Cms\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Routing\RouteProvider;

/**
 * Where the Cms module answers.
 *
 * One route while the module is empty, and it earns its place: it is the only
 * way to tell a build without this module from a build with it by asking the
 * router. A switched-off module registers no provider, so /cms is claimed by
 * nobody and the router says so - there is no catch-all route to answer in its
 * place.
 */
final class CmsRoutes implements RouteProvider
{
    public function provide(RouteList $routes): void
    {
        $routes->addRoute('cms', 'Cms:Front:Status:default');
    }
}
