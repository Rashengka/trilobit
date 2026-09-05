<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Routing\RouteProvider;
use Trilobit\Core\Routing\ShortLinks;

/**
 * The static routes of a stand-in module: where its records live, and the
 * short address that leads there.
 *
 * Both beginnings are declared as reserved in the same class that claims them,
 * which is what decision R6 is about - a route added without that would be a
 * beginning content could still be saved under.
 */
final class DemoRoutes implements RouteProvider
{
    public const string RECORDS = 'demo-records';

    public const string SHORT = 'r';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::RECORDS . '/<id>', 'Demo:Admin:Record:default');
        ShortLinks::add($routes, self::SHORT, 'Demo:Admin:Record:default');
    }

    /** @return list<string> */
    public function reservedSegments(): array
    {
        return [self::RECORDS, self::SHORT];
    }
}
