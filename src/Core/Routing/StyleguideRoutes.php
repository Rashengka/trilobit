<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Presentation\Styleguide\StyleguidePages;

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
final readonly class StyleguideRoutes implements RouteProvider
{
    public const string PATH = '_styleguide';

    public function __construct(
        private StyleguidePages $pages,
    ) {}

    /**
     * The front page of the guide, and a route of its own for every page
     * Trilobit\Core\Presentation\Styleguide\StyleguidePages lists.
     *
     * One fixed route per page rather than one route with the group and the
     * page as parameters, and on purpose: a parameter would answer at every
     * address of its shape, so a mistyped page would reach the presenter and
     * have to be refused there, and the gates reading the routes could not tell
     * which pages exist. With a route per page the router itself knows the
     * list, an address nobody listed is claimed by nobody, and the answer is
     * the same 404 the switch gives.
     *
     * Every page is the same action of the same presenter, told which page it
     * is by the two parameters the route carries - which is also what lets one
     * of them be drawn at a width of its own while the rest are drawn at the
     * reader's (.ai/plans/09-chrome-a-sirka-obsahu.md, L4).
     */
    public function provide(RouteList $routes): void
    {
        foreach ($this->pages->pages() as $page) {
            $routes->addRoute(self::PATH . '/' . $page->path(), [
                'presenter' => 'Core:Styleguide:Overview',
                'action' => 'page',
                'group' => $page->group,
                'page' => $page->slug,
            ]);
        }

        $routes->addRoute(self::PATH, 'Core:Styleguide:Overview:default');
    }

    /** @return list<string> */
    public function reservedSegments(): array
    {
        return [self::PATH];
    }
}
