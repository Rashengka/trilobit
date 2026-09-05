<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Core\Routing\RouteProvider;
use Trilobit\Core\Routing\StyleguideRoutes;

/**
 * The first segments content may never take, because something else answers
 * there.
 *
 * A page called `admin` must not be possible to save. Ordering the routes so
 * that the real one wins is not enough and is the trap this class exists to
 * avoid: the page would save without complaint, look right in the
 * administration, and simply never be reachable - a silent failure nobody
 * meets until a visitor reports a missing page. So the address is refused at
 * the moment somebody tries to create it, with a sentence saying why.
 *
 * Three sources feed the list and each is here for its own reason.
 *
 * - **What Core itself answers**, as constants of the classes that answer,
 *   so the reservation cannot drift from the route.
 * - **Every module this installation declares**, switched on or off. Taking
 *   only the enabled ones would free a segment the moment a module is
 *   switched off, let content move in, and break both the day it comes back.
 * - **Whatever a route provider declares**, for the static routes whose path
 *   is not simply the name of the module that adds them.
 *
 * The list is checked against reality rather than trusted:
 * Trilobit\Tests\Architecture\ReservedSegmentsCoverEveryRouteTest walks the
 * router that was actually built and fails when a static route has a first
 * segment nobody reserved.
 */
final readonly class ReservedSegments
{
    /**
     * Core's own, in every build - the style guide included, even where that
     * page is switched off. A segment that is free in one build and taken in
     * the next is the same trap one door along.
     */
    private const array ALWAYS = [
        AdminRoutes::PATH,
        StyleguideRoutes::PATH,
    ];

    /** @param list<string> $segments */
    private function __construct(private array $segments) {}

    /** @param iterable<RouteProvider> $providers */
    public static function of(ModuleList $modules, iterable $providers): self
    {
        $segments = [...self::ALWAYS, ...$modules->names()];
        foreach ($providers as $provider) {
            foreach ($provider->reservedSegments() as $segment) {
                $segments[] = $segment;
            }
        }

        $segments = array_values(array_unique($segments));
        sort($segments);

        return new self($segments);
    }

    public function isReserved(string $segment): bool
    {
        return in_array($segment, $this->segments, true);
    }

    /** @return list<string> sorted */
    public function all(): array
    {
        return $this->segments;
    }
}
