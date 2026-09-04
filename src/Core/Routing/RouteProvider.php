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
}
