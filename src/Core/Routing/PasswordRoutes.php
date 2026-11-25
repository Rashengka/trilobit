<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Where a password is set with the link somebody was sent - see
 * Trilobit\Core\Security\PasswordLinks.
 *
 * Outside /admin, because the person opening it is not in the administration
 * yet: they have no password to sign in with, which is what the page is for.
 * The leading underscore keeps the path out of the way of content, as the
 * wizard's does; the segment is reserved in every build, because a link sent
 * last week has to keep leading here whatever a business saved since.
 */
final class PasswordRoutes implements RouteProvider
{
    public const string PATH = '_password';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::PATH . '/<token>', 'Core:Admin:Invitation:default');
    }

    /** @return list<string> */
    public function reservedSegments(): array
    {
        return [self::PATH];
    }
}
