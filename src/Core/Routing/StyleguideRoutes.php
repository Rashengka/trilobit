<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Where the style guide answers, when this build has one.
 *
 * The service behind this is registered only while trilobit.styleguide is on
 * (see Trilobit\Core\DI\CoreExtension), which is the whole of the switch. There
 * is no check inside the presenter and no permission to fail: with the switch
 * off nobody claims the path, the router has no catch-all to answer in its
 * place, and the request ends as 404. That is deliberate - 403 would tell a
 * visitor there is something here worth asking about (decision D4).
 *
 * The leading underscore keeps the path out of the way of content: a page or a
 * product will never be called _styleguide.
 */
final class StyleguideRoutes implements RouteProvider
{
    public const string PATH = '_styleguide';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::PATH, 'Core:Styleguide:Overview:default');
    }

    /** @return list<string> */
    public function reservedSegments(): array
    {
        return [self::PATH];
    }
}
