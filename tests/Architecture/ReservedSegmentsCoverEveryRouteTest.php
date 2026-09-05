<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\Application\Routers\RouteList;
use Nette\Routing\Route;
use Nette\Routing\Router;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Content\ReservedSegments;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\RouteProvider;
use Trilobit\Core\Routing\RouterFactory;
use Trilobit\Tests\Boot;

/**
 * Every static route's first segment is reserved, so no content can ever be
 * saved underneath one.
 *
 * This is the half of decision R6 a list cannot state about itself. The
 * refusal at save time is only as good as the list it consults, and a list
 * kept by hand goes stale at the first route somebody adds in a hurry - after
 * which a page saves cleanly, looks right in the administration and is never
 * reachable, because a static route answers at its address first. Nobody meets
 * that until a visitor reports a missing page.
 *
 * So the list is checked against the router that was actually built, in a
 * build with every declared module switched on and the style guide present -
 * the widest set of static routes this checkout can produce. A route added
 * without a reservation fails here, which is the point: the build stops rather
 * than the page disappearing.
 *
 * Routes whose first segment is a parameter are left out. They claim no fixed
 * beginning, so there is nothing for content to collide with.
 */
#[CoversNothing]
final class ReservedSegmentsCoverEveryRouteTest extends TestCase
{
    public function testEveryStaticRouteBeginsWithAReservedSegment(): void
    {
        $container = Boot::container(
            ModuleList::of(array_fill_keys($this->declaredModules(), true), Bootstrap::rootDirectory()),
            styleguide: true,
        );

        self::assertSame(
            [],
            $this->unreservedIn(
                $container->getByType(RouteList::class),
                $container->getByType(ReservedSegments::class),
            ),
        );
    }

    /**
     * The rule above reports nothing, so it would read the same if it looked
     * in the wrong place. Here it is run over a router carrying exactly the
     * mistake it exists to catch - a static route nobody reserved - and has to
     * name it.
     */
    public function testTheRuleReportsARouteNobodyReserved(): void
    {
        $root = Bootstrap::rootDirectory();
        $provider = new class implements RouteProvider {
            public function provide(RouteList $routes): void
            {
                $routes->addRoute('catalogue/<page>', 'Demo:Front:Catalogue:default');
            }

            /** @return list<string> */
            public function reservedSegments(): array
            {
                return [];
            }
        };

        self::assertSame(
            ['catalogue'],
            $this->unreservedIn(
                new RouterFactory([$provider])->create(),
                ReservedSegments::of(ModuleList::of([], $root), [$provider]),
            ),
        );
    }

    /**
     * A provider that does declare its beginning is accepted, so that the rule
     * above is not merely reporting every route it sees.
     */
    public function testTheRuleAcceptsARouteThatWasReserved(): void
    {
        $root = Bootstrap::rootDirectory();
        $provider = new class implements RouteProvider {
            public function provide(RouteList $routes): void
            {
                $routes->addRoute('catalogue/<page>', 'Demo:Front:Catalogue:default');
            }

            /** @return list<string> */
            public function reservedSegments(): array
            {
                return ['catalogue'];
            }
        };

        self::assertSame(
            [],
            $this->unreservedIn(
                new RouterFactory([$provider])->create(),
                ReservedSegments::of(ModuleList::of([], $root), [$provider]),
            ),
        );
    }

    /**
     * @return list<string> the beginnings nobody reserved, sorted
     */
    private function unreservedIn(RouteList $routes, ReservedSegments $reserved): array
    {
        $unreserved = [];
        foreach ($this->staticMasksIn($routes) as $mask) {
            $segment = PublicPath::firstSegment($mask);
            if ($segment !== '' && !$reserved->isReserved($segment)) {
                $unreserved[] = $segment;
            }
        }

        $unreserved = array_values(array_unique($unreserved));
        sort($unreserved);

        return $unreserved;
    }

    /**
     * The masks of every route in the list and in any list nested in it, minus
     * the ones beginning with a parameter or an optional part - those claim no
     * fixed beginning at all.
     *
     * @return list<string>
     */
    private function staticMasksIn(RouteList $routes): array
    {
        $masks = [];
        foreach ($routes->getRouters() as $router) {
            $masks = [...$masks, ...$this->masksOf($router)];
        }

        return $masks;
    }

    /** @return list<string> */
    private function masksOf(Router $router): array
    {
        if ($router instanceof RouteList) {
            return $this->staticMasksIn($router);
        }

        if (!$router instanceof Route) {
            // A router of its own - the catch-all over the register of public
            // addresses is one - claims no fixed beginning by construction.
            return [];
        }

        $mask = ltrim($router->getMask(), '/');
        $first = PublicPath::firstSegment($mask);

        return preg_match('#^[A-Za-z0-9_.-]+$#', $first) === 1 ? [$mask] : [];
    }

    /** @return list<string> */
    private function declaredModules(): array
    {
        $root = Bootstrap::rootDirectory();

        return ModuleList::fromNeon($root . '/config/modules.neon', $root)->names();
    }
}
