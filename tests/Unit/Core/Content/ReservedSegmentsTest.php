<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Content;

use Nette\Application\Routers\RouteList;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Content\ReservedSegments;
use Trilobit\Core\Module\ModuleList;
use Trilobit\Core\Routing\AdminRoutes;
use Trilobit\Core\Routing\PreferenceRoutes;
use Trilobit\Core\Routing\RouteProvider;
use Trilobit\Core\Routing\StyleguideRoutes;

#[CoversClass(ReservedSegments::class)]
final class ReservedSegmentsTest extends TestCase
{
    public function testCoresOwnBeginningsAreReservedInEveryBuild(): void
    {
        $reserved = ReservedSegments::of($this->modules([]), []);

        self::assertTrue($reserved->isReserved(AdminRoutes::PATH));
        self::assertTrue($reserved->isReserved(StyleguideRoutes::PATH));
    }

    /**
     * The style guide is registered only where that page exists, and its
     * beginning is reserved anyway. A segment that is free in one build and
     * taken in the next is the same silent failure the reservation exists to
     * prevent, one door along: content would move in while the page is off and
     * disappear the day somebody turns it on.
     */
    public function testTheStyleGuideBeginningIsReservedEvenWithNoProviderForIt(): void
    {
        self::assertTrue(ReservedSegments::of($this->modules([]), [])->isReserved(StyleguideRoutes::PATH));
    }

    /**
     * Every declared module, not every enabled one, for the same reason: a
     * module that is switched off still owns its name in the address space,
     * or switching it back on would collide with whatever moved in meanwhile.
     */
    public function testEveryDeclaredModuleNameIsReservedWhetherItIsOnOrOff(): void
    {
        $reserved = ReservedSegments::of($this->modules(['alpha' => true, 'beta' => false]), []);

        self::assertTrue($reserved->isReserved('alpha'));
        self::assertTrue($reserved->isReserved('beta'));
    }

    public function testAProviderAddsWhateverItDeclares(): void
    {
        $reserved = ReservedSegments::of($this->modules([]), [$this->provider(['c', 's'])]);

        self::assertTrue($reserved->isReserved('c'));
        self::assertTrue($reserved->isReserved('s'));
    }

    /**
     * A reservation is recognised in the spelling it was declared in and in the
     * one normalising makes of it, because both arrive here.
     *
     * The style guide is the case that made this necessary and it is not a
     * corner: its path begins with an underscore on purpose, so that no page or
     * product can ever be called that - and normalising rewrites anything
     * outside the English alphabet, so a request for it is held against the
     * list spelled `styleguide`. A list that knew only what was declared
     * answered no, and the caller had to know to ask twice. Knowing both here
     * is what stops the next caller from having to know it.
     */
    public function testAReservationIsRecognisedInBothItsSpellings(): void
    {
        $reserved = ReservedSegments::of($this->modules([]), []);

        self::assertTrue($reserved->isReserved(StyleguideRoutes::PATH));
        self::assertTrue($reserved->isReserved(PublicPath::normalize(StyleguideRoutes::PATH)));
    }

    /**
     * And in any other spelling of what was asked for, which is the same
     * knowledge used from the other end: the caller hands over what a visitor
     * typed, not something it had to tidy up first.
     */
    public function testAReservationIsRecognisedHoweverItWasTypedFor(): void
    {
        $reserved = ReservedSegments::of($this->modules(['alpha' => true]), []);

        self::assertTrue($reserved->isReserved('ALPHA'));
        self::assertTrue($reserved->isReserved(strtoupper(StyleguideRoutes::PATH)));
    }

    public function testAnythingNobodyClaimedIsFreeForContent(): void
    {
        $reserved = ReservedSegments::of($this->modules(['alpha' => true]), [$this->provider(['c'])]);

        self::assertFalse($reserved->isReserved('bikes'));
        self::assertFalse($reserved->isReserved(''));
    }

    public function testTheListIsSortedAndHoldsNoDuplicates(): void
    {
        $reserved = ReservedSegments::of(
            $this->modules(['alpha' => true]),
            [$this->provider(['alpha', 'c']), $this->provider(['c'])],
        );

        self::assertSame(
            [PreferenceRoutes::PATH, StyleguideRoutes::PATH, AdminRoutes::PATH, 'alpha', 'c'],
            $reserved->all(),
        );
    }

    /** @param array<string, bool> $modules */
    private function modules(array $modules): ModuleList
    {
        return ModuleList::of($modules, dirname(__DIR__, 5));
    }

    /** @param list<string> $segments */
    private function provider(array $segments): RouteProvider
    {
        return new readonly class ($segments) implements RouteProvider {
            /** @param list<string> $segments */
            public function __construct(private array $segments) {}

            public function provide(RouteList $routes): void {}

            /** @return list<string> */
            public function reservedSegments(): array
            {
                return $this->segments;
            }
        };
    }
}
