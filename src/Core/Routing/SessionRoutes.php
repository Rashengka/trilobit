<?php

declare(strict_types=1);

namespace Trilobit\Core\Routing;

use Nette\Application\Routers\RouteList;

/**
 * Where somebody ends the session they are in, whoever they are.
 *
 * **Signing in differs by audience; signing out does not.** An administrator
 * signs in on a page of the administration and lands in it; somebody buying
 * something will one day sign in somewhere else entirely and land somewhere
 * else again. But there is one identity and one session, so ending it is one
 * act - the same act, whoever is doing it - and it therefore has one address
 * for the whole application rather than one per part of it. This used to live
 * at `admin/sign-out`, which said the opposite: the day a customer needed to
 * sign out, the application would either have grown a second way of doing one
 * thing or would have been sending customers to an administration address.
 *
 * **The address is readable rather than hidden, and that is a trade.** The
 * other address of Core's that is not content is `_preference`, spelled with a
 * leading underscore so that nothing can ever be called that; it is a target
 * for a script and nobody types it. This one a person may type, bookmark or be
 * sent, so it reads as what it does - and the price is that no page and no
 * product of any tenant can ever live at `/sign-out`, which is a segment given
 * up for good. It is worth it here and would not be worth it for an endpoint
 * nobody sees.
 *
 * Signing in has deliberately not moved with it. It is still the
 * administration's own page, because arriving somewhere is the half that
 * differs by who is arriving.
 */
final class SessionRoutes implements RouteProvider
{
    public const string SIGN_OUT_PATH = 'sign-out';

    public function provide(RouteList $routes): void
    {
        $routes->addRoute(self::SIGN_OUT_PATH, 'Core:Session:SignOut:default');
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
        return [self::SIGN_OUT_PATH];
    }
}
