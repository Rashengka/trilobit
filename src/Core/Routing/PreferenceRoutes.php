<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Where a choice about the way the application looks is written down.
 *
 * It is one address for the whole application rather than a signal on the page
 * the switch happens to be on. The switch is meant to end up in the chrome of
 * every page (decision D8), and a signal would have to be added to each base
 * presenter and then to whichever one comes next - which is the sort of list
 * that is complete until somebody writes the fourth.
 *
 * The leading underscore keeps the path out of the way of content, the same way
 * the style guide's does: a page or a product will never be called _preference.
 */
final class PreferenceRoutes implements RouteProvider
{
    public const string PATH = '_preference';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::PATH, 'Core:Preference:Choice:remember');
    }

    /**
     * Declared here as well as in Trilobit\Core\Content\ReservedSegments,
     * which holds it as one of Core's own: the constant is the one place the
     * path is written, so the two cannot disagree.
     *
     * @return list<string>
     */
    public function reservedSegments(): array
    {
        return [self::PATH];
    }
}
