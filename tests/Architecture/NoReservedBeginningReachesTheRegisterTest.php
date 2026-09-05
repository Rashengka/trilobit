<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture;

use Nette\DI\Container;
use Nette\Http\Request;
use Nette\Http\UrlScript;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Bootstrap;
use Trilobit\Core\Content\Address;
use Trilobit\Core\Content\ContentTypes;
use Trilobit\Core\Content\PathLookup;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Content\ReservedSegments;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\ContentRouter;
use Trilobit\Tests\Boot;
use Trilobit\Tests\Double\RecordingPathLookup;

/**
 * No request under a reserved beginning ever reaches the register of public
 * addresses - for every reservation this build declares, not for the one
 * somebody remembered to write a case for.
 *
 * This is the other half of decision R6, and the half its sibling
 * Trilobit\Tests\Architecture\ReservedSegmentsCoverEveryRouteTest does not
 * state. That one asks whether the list covers the routes, which is a question
 * about the list. This one asks what the list is for: an address under a
 * beginning something else answers at can never be a row, so asking the
 * register about one is a query on every request for an address that will
 * never be there, and in a build whose schema is not there yet it turns a page
 * that should end as 404 into an error.
 *
 * The two questions came apart once already, which is why this file exists.
 * The style guide answers at `_styleguide`, spelled with an underscore on
 * purpose so that no page or product can ever be called that - and normalising
 * an address rewrites anything outside the English alphabet, so the reservation
 * arrived at the comparison spelled `styleguide` and stopped matching. The list
 * covered the route the whole time and its guard stayed green.
 *
 * Hence both spellings below. A reservation reaches the router in the shape it
 * was declared in and in the shape normalising makes of it - a visitor types
 * either - and the register can hold neither, so neither may get that far. A
 * reservation declared tomorrow in a spelling nobody anticipated is covered the
 * moment it is declared, because the cases are the list itself.
 */
#[CoversNothing]
final class NoReservedBeginningReachesTheRegisterTest extends TestCase
{
    /**
     * The widest set of reservations this checkout can produce - every declared
     * module switched on and the style guide present - so that a reservation is
     * covered wherever it comes from.
     */
    private static ?Container $widestBuild = null;

    /** @return iterable<string, array{string}> */
    public static function reservedBeginnings(): iterable
    {
        foreach (self::reservedSegments()->all() as $segment) {
            yield $segment => [$segment];
        }
    }

    #[DataProvider('reservedBeginnings')]
    public function testNothingUnderAReservedBeginningIsLookedUp(string $segment): void
    {
        $router = $this->routerOver($this->registerThatRefusesToBeAsked());

        foreach ($this->spellingsOf($segment) as $spelling) {
            self::assertNull($router->match($this->request('/' . $spelling)));
            self::assertNull($router->match($this->request('/' . $spelling . '/anything')));
        }
    }

    /**
     * The counterweight, without which every case above would pass in a router
     * that refused everything: an address nobody reserved is the register's
     * business and has to reach it.
     */
    public function testAnAddressNobodyReservedIsLookedUp(): void
    {
        $register = new RecordingPathLookup();

        self::assertNull($this->routerOver($register)->match($this->request('/bikes/mountain')));
        self::assertSame(['bikes/mountain'], $register->asked());
    }

    /**
     * The spellings of one reservation that can arrive at the router: the one
     * it was declared in, and the one normalising makes of it. They are the
     * same string for a reservation made of plain letters, and that is the
     * ordinary case - it is the other kind this is here for.
     *
     * @return list<string>
     */
    private function spellingsOf(string $segment): array
    {
        $normalized = PublicPath::normalize($segment);

        return $normalized === '' || $normalized === $segment ? [$segment] : [$segment, $normalized];
    }

    private function routerOver(PathLookup $register): ContentRouter
    {
        return new ContentRouter($register, new ContentTypes([]), self::reservedSegments());
    }

    private function request(string $path): Request
    {
        return new Request(new UrlScript('http://localhost' . $path, '/'));
    }

    /**
     * A register that cannot be read, so that reaching it is a failure with a
     * sentence on it rather than an empty answer that looks like agreement.
     */
    private function registerThatRefusesToBeAsked(): PathLookup
    {
        return new class implements PathLookup {
            public function find(string $path): ?Address
            {
                throw new \LogicException(sprintf("the register was asked about '%s', and must not have been", $path));
            }

            public function canonicalPathOf(ContentRef $ref): ?string
            {
                throw new \LogicException('the register was asked for a permalink, and must not have been');
            }
        };
    }

    private static function reservedSegments(): ReservedSegments
    {
        $root = Bootstrap::rootDirectory();
        self::$widestBuild ??= Boot::container(
            ModuleList::of(
                array_fill_keys(ModuleList::fromNeon($root . '/config/modules.neon', $root)->names(), true),
                $root,
            ),
            styleguide: true,
        );

        return self::$widestBuild->getByType(ReservedSegments::class);
    }
}
