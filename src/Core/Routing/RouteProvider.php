<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * A module contributes its routes by registering a service that implements
 * this and carries the tag Trilobit\Core\DI\CoreExtension::TAG_ROUTE_PROVIDER.
 *
 * Core never names a module. A module that is switched off registers no
 * service, so it contributes no route, and a link into it fails loudly
 * instead of quietly resolving to nothing.
 */
interface RouteProvider
{
    public function provide(RouteList $routes): void;

    /**
     * The first segments the routes above claim, so that content can never be
     * saved underneath one of them.
     *
     * It is on this interface rather than in a list somewhere else, because
     * adding a route and deciding what it takes out of the public address
     * space are one act. Written apart, the two drift the first time somebody
     * is in a hurry - and drift looks like a page that saves cleanly and is
     * then never reachable again.
     *
     * A provider whose routes all sit under a segment that is reserved
     * already - the name of its own module, say - returns an empty list. The
     * declaration is not taken on trust either way:
     * Trilobit\Tests\Architecture\ReservedSegmentsCoverEveryRouteTest reads the
     * router that was actually built and fails on a static route whose
     * beginning nobody reserved.
     *
     * @return list<string>
     */
    public function reservedSegments(): array;
}
