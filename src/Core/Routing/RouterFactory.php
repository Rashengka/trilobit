<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;
use Nette\Routing\Router;

/**
 * Builds the router from what Core owns, whatever the enabled modules
 * contributed, and last the register of public addresses.
 *
 * The order is the whole of the address space and it runs from the most
 * specific to the least:
 *
 * 0. the root, which is Core's own homepage;
 * 1. the static routes, Core's first so that adding a module cannot move the
 *    homepage - a module that wants the front page has to say so in a change
 *    somebody can read;
 * 2. whatever short addresses a module registers, which redirect rather than
 *    draw;
 * 3. the register of public addresses, which claims everything left.
 *
 * The catch-all is last and it is not a wildcard: it answers only for a path
 * the register really holds, and refuses anything under a reserved beginning
 * before it even looks. So a request for a switched-off module's own path
 * still comes back unrouted, which is what lets a suite tell a build with a
 * module from one without it by asking the router.
 *
 * The catch-all is handed in rather than built here, because what it consults
 * is a database and this class is the one place the shape of the router can be
 * read at a glance.
 */
final readonly class RouterFactory
{
    /**
     * @param iterable<RouteProvider> $providers
     * @param Router|null $content the register of public addresses; null in a
     *     build assembled without one, which is a test rather than a
     *     deployment - Core always registers it.
     */
    public function __construct(
        private iterable $providers,
        private ?Router $content = null,
    ) {}

    public function create(): RouteList
    {
        $routes = new RouteList();
        $routes->addRoute('', 'Core:Front:Home:default');

        foreach ($this->providers as $provider) {
            $provider->provide($routes);
        }

        if ($this->content instanceof Router) {
            $routes->add($this->content);
        }

        return $routes;
    }
}
