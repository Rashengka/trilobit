<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Builds the router from what Core owns plus whatever the enabled modules
 * contributed.
 *
 * Two decisions are worth stating, because both are load-bearing later:
 *
 * - There is no catch-all route. A path nobody claimed does not match, so a
 *   request for a switched-off module's URL comes back as null. A catch-all
 *   would answer for the module and turn its absence into a broken page.
 * - Core's own routes are added first, so that adding a module cannot move the
 *   homepage. A module that wants the front page has to say so explicitly, in
 *   a change somebody can read.
 */
final readonly class RouterFactory
{
    /** @param iterable<RouteProvider> $providers */
    public function __construct(
        private iterable $providers,
    ) {}

    public function create(): RouteList
    {
        $routes = new RouteList();
        $routes->addRoute('', 'Core:Front:Home:default');

        foreach ($this->providers as $provider) {
            $provider->provide($routes);
        }

        return $routes;
    }
}
