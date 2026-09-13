<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Navigation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Navigation\Arrangement;
use Trilobit\Core\Navigation\Composition;
use Trilobit\Core\Navigation\NavigationContributor;
use Trilobit\Core\Navigation\NavigationEntry;

/**
 * How the top level of a menu is put together out of what contributes to it,
 * and what an arrangement somebody saved changes about that
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * What is saved is a sequence of places, each one naming a contributor, and
 * never an entry: the entry in a place is the next one of that contributor, in
 * the contributor's own order. So "an article in the middle and the categories
 * around it" is three places for the categories and one for the pages - and
 * the order inside one contributor cannot be saved wrong, because it is not
 * saved at all.
 *
 * The contributors are invented. What is asked is the arithmetic of places,
 * which is the same whoever contributes.
 */
#[CoversClass(Arrangement::class)]
#[CoversClass(Composition::class)]
final class ArrangementTest extends TestCase
{
    private const string MENU = 'main';

    public function testWithNothingSavedTheContributorsFollowTheirWeightEachInItsOwnOrder(): void
    {
        $arrangement = Arrangement::of($this->site(), null, self::MENU);

        self::assertSame(['Home', 'About us', 'Customer care', 'Elsewhere', 'Cms', 'Shop'], $this->labels($arrangement));
        self::assertFalse($arrangement->isComposed());
    }

    public function testContributorsOfTheSameWeightAreOrderedByTheirKey(): void
    {
        $arrangement = Arrangement::of(
            [$this->contributor('second', 10, 'Second'), $this->contributor('first', 10, 'First')],
            null,
            self::MENU,
        );

        self::assertSame(['First', 'Second'], $this->labels($arrangement));
    }

    public function testOnlyTheMenuAskedForIsDrawn(): void
    {
        self::assertSame([], $this->labels(Arrangement::of($this->site(), null, 'footer')));
    }

    /** The example the decision was made on: an article in the middle, and the categories around it. */
    public function testASavedOrderInterleavesTheEntriesOfOneContributorWithAnothers(): void
    {
        $arrangement = Arrangement::of(
            [$this->contributor('categories', 10, 'Bicycles', 'Helmets', 'Lights'), $this->contributor('pages', 20, 'Our story')],
            new Composition(['categories', 'categories', 'pages', 'categories']),
            self::MENU,
        );

        self::assertSame(['Bicycles', 'Helmets', 'Our story', 'Lights'], $this->labels($arrangement));
        self::assertTrue($arrangement->isComposed());
    }

    /**
     * A contributor with more entries than it has places puts the rest after
     * its last place - its own order goes on where it left off - and a
     * contributor the saved order does not mention at all comes last, so that
     * nothing a module adds later disappears for not having been arranged.
     */
    public function testWhatTheSavedOrderHasNoPlaceForFollowsItsContributorOrComesLast(): void
    {
        $arrangement = Arrangement::of($this->site(), new Composition(['menu', 'home']), self::MENU);

        self::assertSame(['About us', 'Customer care', 'Elsewhere', 'Home', 'Cms', 'Shop'], $this->labels($arrangement));
    }

    public function testAHiddenContributorIsNotDrawnAndIsSaidToBeHidden(): void
    {
        $arrangement = Arrangement::of($this->site(), new Composition([], ['sections']), self::MENU);

        self::assertSame(['Home', 'About us', 'Customer care', 'Elsewhere'], $this->labels($arrangement));
        self::assertSame(['home' => false, 'menu' => false, 'sections' => true], $this->hiddenBySource($arrangement));
    }

    /**
     * A place saved for a contributor this build does not have - a module
     * switched off - or for more entries than a contributor has today is kept
     * rather than forgotten, the way a menu entry leading into a switched-off
     * module waits for it: arranging something else must not throw away what
     * the module will come back to.
     */
    public function testAPlaceNothingFillsIsKeptInWhatIsSavedNext(): void
    {
        $arrangement = Arrangement::of($this->site(), new Composition(['gone', 'home', 'home', 'menu'], ['gone']), self::MENU);
        self::assertSame(['Home', 'About us', 'Customer care', 'Elsewhere', 'Cms', 'Shop'], $this->labels($arrangement));

        $next = $arrangement->movedDown(0);

        self::assertSame(['gone', 'menu', 'home', 'home', 'menu', 'menu', 'sections', 'sections'], $next->order);
        self::assertSame(['gone'], $next->hidden);
        self::assertSame(
            ['About us', 'Home', 'Customer care', 'Elsewhere', 'Cms', 'Shop'],
            $this->labels(Arrangement::of($this->site(), $next, self::MENU)),
        );
    }

    /**
     * An entry moves past a neighbour of another contributor, and never past
     * one of its own: the order inside a contributor is the contributor's (the
     * position of a menu entry among its siblings), and a second place to say
     * it would be a second answer that could disagree with the first.
     */
    public function testAnEntryMovesPastANeighbourOfAnotherContributorOnly(): void
    {
        // Home | About us, Customer care, Elsewhere | Cms, Shop
        $arrangement = Arrangement::of($this->site(), null, self::MENU);

        self::assertFalse($arrangement->canMoveUp(0), 'the first entry has nothing above it');
        self::assertTrue($arrangement->canMoveDown(0));
        self::assertTrue($arrangement->canMoveUp(1));
        self::assertFalse($arrangement->canMoveDown(1), 'the entry below is of the same contributor');
        self::assertFalse($arrangement->canMoveUp(2), 'the entry above is of the same contributor');
        self::assertTrue($arrangement->canMoveDown(3));
        self::assertFalse($arrangement->canMoveDown(5), 'the last entry has nothing below it');

        self::assertSame(
            ['Home', 'About us', 'Customer care', 'Cms', 'Elsewhere', 'Shop'],
            $this->labels(Arrangement::of($this->site(), $arrangement->movedDown(3), self::MENU)),
        );
        self::assertSame(
            ['About us', 'Home', 'Customer care', 'Elsewhere', 'Cms', 'Shop'],
            $this->labels(Arrangement::of($this->site(), $arrangement->movedUp(1), self::MENU)),
        );
    }

    public function testAMoveThatCannotBeMadeIsRefusedRatherThanIgnored(): void
    {
        $this->expectException(\LogicException::class);

        Arrangement::of($this->site(), null, self::MENU)->movedDown(1);
    }

    /** What is hidden keeps its places, so showing it again puts it back where it was. */
    public function testMovingPassesTheNearestDrawnNeighbourAndLeavesHiddenPlacesAlone(): void
    {
        $hidden = Arrangement::of($this->site(), new Composition([], ['menu']), self::MENU);
        self::assertSame(['Home', 'Cms', 'Shop'], $this->labels($hidden));

        $moved = $hidden->movedDown(0);
        $arranged = Arrangement::of($this->site(), $moved, self::MENU);

        self::assertSame(['Cms', 'Home', 'Shop'], $this->labels($arranged));
        self::assertSame(
            ['Cms', 'About us', 'Customer care', 'Elsewhere', 'Home', 'Shop'],
            $this->labels(Arrangement::of($this->site(), $arranged->showing('menu'), self::MENU)),
        );
    }

    public function testHidingAndShowingAContributorKeepTheOrderItIsIn(): void
    {
        $hidden = Arrangement::of($this->site(), null, self::MENU)->hiding('sections');

        self::assertSame(['sections'], $hidden->hidden);
        self::assertSame(['home', 'menu', 'menu', 'menu', 'sections', 'sections'], $hidden->order);
        self::assertSame([], Arrangement::of($this->site(), $hidden, self::MENU)->showing('sections')->hidden);
    }

    public function testAnEntryKeepsWhatIsUnderIt(): void
    {
        $care = NavigationEntry::toPath('customer-care', 'Customer care', 'customer-care', [
            NavigationEntry::toPath('delivery', 'Delivery', 'delivery'),
            NavigationEntry::toPath('returns', 'Returns', 'returns'),
        ]);

        $entries = Arrangement::of([$this->contributor('menu', 0, $care)], null, self::MENU)->entries();

        self::assertSame(['Delivery', 'Returns'], array_map(static fn(NavigationEntry $entry): string => $entry->label, $entries[0]->children));
    }

    public function testASavedArrangementIsReadBackAsItWasWritten(): void
    {
        self::assertSame(
            ['order' => ['home', 'menu'], 'hidden' => ['sections']],
            Composition::fromArray(['order' => ['home', 'menu'], 'hidden' => ['sections']])->toArray(),
        );
        self::assertSame(['order' => [], 'hidden' => []], Composition::fromArray([])->toArray());
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function malformed(): iterable
    {
        yield 'an order with something other than a name in it' => [['order' => ['home', 3]]];
        yield 'an order keyed by name' => [['order' => ['first' => 'home']]];
        yield 'a hidden that is not a list' => [['hidden' => 'sections']];
    }

    /**
     * Only the application writes an arrangement, so one of another shape is
     * a mistake somebody has to see - not something to be read as "nothing
     * arranged", which would look exactly like the default on purpose.
     *
     * @param array<mixed> $stored
     */
    #[DataProvider('malformed')]
    public function testASavedArrangementOfAnotherShapeIsRefused(array $stored): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Composition::fromArray($stored);
    }

    /** @return list<NavigationContributor> an invented site's three contributors, in no particular order */
    private function site(): array
    {
        return [
            $this->contributor('sections', 200, 'Cms', 'Shop'),
            $this->contributor('home', 0, 'Home'),
            $this->contributor('menu', 100, 'About us', 'Customer care', 'Elsewhere'),
        ];
    }

    private function contributor(string $key, int $weight, NavigationEntry|string ...$entries): NavigationContributor
    {
        $made = [];
        foreach ($entries as $entry) {
            $made[] = $entry instanceof NavigationEntry
                ? $entry
                : NavigationEntry::toPath(strtolower(str_replace(' ', '-', $entry)), $entry, strtolower(str_replace(' ', '-', $entry)));
        }

        return new readonly class ($key, $weight, $made) implements NavigationContributor {
            /** @param list<NavigationEntry> $entries */
            public function __construct(
                private string $key,
                private int $weight,
                private array $entries,
            ) {}

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return ucfirst($this->key);
            }

            public function weight(): int
            {
                return $this->weight;
            }

            public function entriesOf(string $menu): array
            {
                return $menu === 'main' ? $this->entries : [];
            }
        };
    }

    /** @return list<string> */
    private function labels(Arrangement $arrangement): array
    {
        return array_map(static fn(NavigationEntry $entry): string => $entry->label, $arrangement->entries());
    }

    /** @return array<string, bool> */
    private function hiddenBySource(Arrangement $arrangement): array
    {
        $hidden = [];
        foreach ($arrangement->sources() as $source) {
            $hidden[$source->key] = $source->hidden;
        }

        return $hidden;
    }
}
