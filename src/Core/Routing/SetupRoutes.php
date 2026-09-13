<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Where the setup wizard answers - while there is a wizard.
 *
 * The route is registered in every build and matches whatever the data says;
 * whether there is anything behind it is the presenter's question, asked of
 * the database on every request, and the answer once the installation holds
 * an account or a business is 404, the same answer as an address nobody
 * claims (decision O3 in .ai/plans/23-instalace-na-zelene-louce.md). The router cannot ask it:
 * a route that matched only while the database agreed would put a query in
 * front of routing every request, and a database that cannot be reached is
 * precisely when the wizard has something to say.
 *
 * The leading underscore keeps the path out of the way of content, as the
 * style guide's does: nothing a business saves can ever be called that, and
 * the segment is reserved for good although the wizard lives only until the
 * first administrator exists - a segment free today and taken on the next
 * fresh installation is the trap Trilobit\Core\Content\ReservedSegments exists
 * to close.
 */
final class SetupRoutes implements RouteProvider
{
    public const string PATH = '_setup';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::PATH, 'Core:Setup:Wizard:default');
    }

    /** @return list<string> */
    public function reservedSegments(): array
    {
        return [self::PATH];
    }

    /**
     * Whether $path - as Nette\Http\UrlScript::getPathInfo() gives it, relative
     * to where the application is served from - is the wizard's.
     *
     * Asked by Trilobit\Core\Tenancy\TenantFromHost before anything is routed,
     * because the wizard is the one page that has to answer at a host no
     * business claims: on a fresh installation there is no business at all.
     * The first segment is compared whole, so a path that merely begins with
     * the same letters is not the wizard's.
     */
    public static function isTheWizard(string $path): bool
    {
        return explode('/', ltrim($path, '/'), 2)[0] === self::PATH;
    }
}
