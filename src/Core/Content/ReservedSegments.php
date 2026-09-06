<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Core\Routing\PreferenceRoutes;
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
 * A reservation is held in two spellings and answered for in either, which is
 * the whole of what this class knows that a plain list does not - see
 * isReserved().
 *
 * The list is checked against reality rather than trusted, twice over:
 * Trilobit\Tests\Architecture\ReservedSegmentsCoverEveryRouteTest walks the
 * router that was actually built and fails when a static route has a first
 * segment nobody reserved, and
 * Trilobit\Tests\Architecture\NoReservedBeginningReachesTheRegisterTest asks
 * of every segment in this list, in both spellings, that a request for it
 * never reaches the register.
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
        PreferenceRoutes::PATH,
        StyleguideRoutes::PATH,
    ];

    /**
     * @param list<string> $segments what was declared, sorted
     * @param list<string> $spellings the same, plus the shape normalising makes
     *     of each - see isReserved()
     */
    private function __construct(private array $segments, private array $spellings) {}

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

        return new self($segments, self::spellingsOf($segments));
    }

    /**
     * Whether something other than content answers at $segment, in whatever
     * shape $segment arrives in.
     *
     * Both halves of that are the point of this class, and the reason it is not
     * an array with in_array() next to it.
     *
     * A reservation need not be a storable address, and the useful ones are
     * not: the style guide answers at `_styleguide`, spelled with an underscore
     * precisely so that no page or product can ever be called that. Normalising
     * an address rewrites anything outside the English alphabet, so that
     * reservation reaches a comparison spelled `styleguide` - a different
     * string, and one no declaration mentions. Both spellings are therefore
     * held, computed once when the list is built, and both are reserved: the
     * declared one because it is what a visitor types, the normalised one
     * because it is what the address would be stored as, and content sitting
     * there would be reachable by asking for the reservation.
     *
     * And $segment is normalised on the way in, so that a caller may hand over
     * what was asked for rather than something it had to tidy up first. That
     * is not a convenience. This question used to be answered correctly only
     * because Trilobit\Core\Routing\ContentRouter knew to ask it twice, once
     * per spelling - knowledge on the wrong side of the call, which the second
     * caller would not have had, and whose absence fails the way everything
     * here fails: silently, at an address nobody can reach.
     */
    public function isReserved(string $segment): bool
    {
        return in_array($segment, $this->spellings, true)
            || in_array(PublicPath::normalize($segment), $this->spellings, true);
    }

    /** @return list<string> what was declared, sorted */
    public function all(): array
    {
        return $this->segments;
    }

    /**
     * @param list<string> $segments
     *
     * @return list<string>
     */
    private static function spellingsOf(array $segments): array
    {
        $spellings = $segments;
        foreach ($segments as $segment) {
            $normalized = PublicPath::normalize($segment);
            // A reservation made of nothing normalising keeps - the empty
            // address is the root, which is a static route and never a row.
            if ($normalized !== '') {
                $spellings[] = $normalized;
            }
        }

        return array_values(array_unique($spellings));
    }
}
