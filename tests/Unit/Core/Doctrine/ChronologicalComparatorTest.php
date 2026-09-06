<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Doctrine;

use Doctrine\Migrations\Version\AlphabeticalComparator;
use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Doctrine\ChronologicalComparator;

/**
 * Migrations run in the order they were written, not in the order the modules
 * happen to be named.
 *
 * The claim is made against the answer Doctrine gives on its own, in the same
 * test, because the two agree about almost everything: they differ only where
 * one module's migration is newer than another's and the module's name sorts
 * earlier. That is one case, it is the case a fresh installation meets, and a
 * test that did not put the two side by side would not show that anything had
 * changed at all.
 */
#[CoversClass(ChronologicalComparator::class)]
final class ChronologicalComparatorTest extends TestCase
{
    /**
     * A module's migration, written after Core's - which is the ordinary way
     * round, because Core is what a module's tables point at.
     *
     * The names below are shaped like the real ones and are deliberately not
     * any of them. What is under test is a rule about names, so a test tied to
     * the migrations that happen to exist today would fail the day one of them
     * is squashed away, and would be saying nothing about the rule when it did.
     */
    private const string LATER_IN_A_MODULE = 'Trilobit\Cms\Migrations\Version20990102030405';

    /** Core's, written earlier, and the one the module's tables point at. */
    private const string EARLIER_IN_CORE = 'Trilobit\Core\Migrations\Version20990101000000';

    public function testAMigrationWrittenLaterRunsLaterEvenWhereItsModuleIsNamedEarlier(): void
    {
        self::assertGreaterThan(
            0,
            $this->order(new ChronologicalComparator(), self::LATER_IN_A_MODULE, self::EARLIER_IN_CORE),
        );
    }

    /**
     * The behaviour being replaced, stated so that the test above is known to
     * be about something. Doctrine compares the whole class name, so Cms sorts
     * before Core whatever the timestamps say - and a fresh installation then
     * creates a module's table with a foreign key into one of Core's that does
     * not exist yet.
     */
    public function testTheAnswerBeingReplacedPutsThemTheOtherWayRound(): void
    {
        self::assertLessThan(
            0,
            $this->order(new AlphabeticalComparator(), self::LATER_IN_A_MODULE, self::EARLIER_IN_CORE),
        );
    }

    public function testAMigrationWrittenEarlierRunsEarlier(): void
    {
        self::assertLessThan(
            0,
            $this->order(new ChronologicalComparator(), self::EARLIER_IN_CORE, self::LATER_IN_A_MODULE),
        );
    }

    /** Two written in the same second keep the only order there is left: the name. */
    public function testTwoWrittenInTheSameSecondAreOrderedByName(): void
    {
        $comparator = new ChronologicalComparator();

        self::assertLessThan(
            0,
            $this->order(
                $comparator,
                'Trilobit\Cms\Migrations\Version20990101000000',
                'Trilobit\Core\Migrations\Version20990101000000',
            ),
        );
    }

    /** A migration is the same as itself, or sorting would depend on which one was asked about first. */
    public function testAMigrationIsTheSameAsItself(): void
    {
        self::assertSame(
            0,
            $this->order(new ChronologicalComparator(), self::EARLIER_IN_CORE, self::EARLIER_IN_CORE),
        );
    }

    /**
     * A name this project did not generate carries no timestamp, so there is
     * nothing to order it by but the name - which is the answer Doctrine gives
     * on its own rather than an invented one.
     */
    public function testAMigrationWithNoTimestampInItsNameFallsBackToTheName(): void
    {
        $comparator = new ChronologicalComparator();

        self::assertLessThan(0, $this->order($comparator, 'A\Handwritten', 'B\Handwritten'));
        self::assertLessThan(0, $this->order($comparator, 'A\Handwritten', self::EARLIER_IN_CORE));
    }

    private function order(Comparator $comparator, string $left, string $right): int
    {
        return $comparator->compare(new Version($left), new Version($right));
    }
}
