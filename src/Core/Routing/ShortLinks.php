<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;
use Trilobit\Core\Presentation\Front\ShortLinkPresenter;

/**
 * How a module adds a short address for its records, and what it costs.
 *
 * The cost is the reason this is one call rather than a route written out by
 * hand: a prefix takes a beginning out of the public address space for good,
 * so whoever adds one has to return it from
 * Trilobit\Core\Routing\RouteProvider::reservedSegments() in the same breath -
 * and ReservedSegmentsCoverEveryRouteTest fails the build if they do not.
 *
 * Two of these are cheap and six are a menagerie. The exit condition is
 * written down in decision R9: while there are at most four they stay single
 * letters; a fifth means moving to one shared shape and leaving the old ones
 * as redirects.
 */
final class ShortLinks
{
    /**
     * Adds `<prefix>/<id>`, which answers 301 to $destination with the same
     * identifier.
     *
     * @param string $destination a presenter and an action, as a link names
     *     them: `Backoffice:Admin:Record:default`. It is resolved when
     *     somebody follows the short address, so a build without the module
     *     that owns it fails loudly there rather than silently redirecting to
     *     nothing - and in such a build the module registers no route here
     *     either.
     */
    public static function add(RouteList $routes, string $prefix, string $destination): void
    {
        $routes->addRoute($prefix . '/<' . ShortLinkPresenter::ID . '>', [
            'presenter' => ShortLinkPresenter::NAME,
            'action' => 'default',
            ShortLinkPresenter::DESTINATION => $destination,
        ]);
    }
}
