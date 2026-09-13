<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation\Listing;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Listing\Comparison;
use Trilobit\Core\Presentation\Listing\Filter;
use Trilobit\Core\Presentation\Listing\Filters;
use Trilobit\Core\Presentation\Listing\Reading;

/**
 * What a listing makes of the filters an address asks for, before anything
 * is asked of the database.
 *
 * The claim is the allow-list: only a filter the listing was configured with
 * reaches the query, and anything else in the address is set aside with a
 * sentence rather than dropped without one. A value quietly dropped leaves a
 * list that looks filtered and is not; a value quietly kept is a column the
 * address chose.
 */
#[CoversClass(Filters::class)]
#[CoversClass(Filter::class)]
#[CoversClass(Reading::class)]
final class FiltersTest extends TestCase
{
    public function testAConfiguredFilterIsRead(): void
    {
        $reading = $this->filters()->read(['title' => 'ride', 'status' => 'draft']);

        self::assertSame(['title' => 'ride', 'status' => 'draft'], $reading->values);
        self::assertSame([], $reading->setAside);
        self::assertTrue($reading->isFiltered());
    }

    /** An empty field is a filter nobody used, which is not something to say anything about. */
    public function testAnEmptyValueIsNoFilter(): void
    {
        $reading = $this->filters()->read(['title' => '  ', 'status' => '']);

        self::assertSame([], $reading->values);
        self::assertSame([], $reading->setAside);
        self::assertFalse($reading->isFiltered());
    }

    public function testWhatIsTypedIsTrimmed(): void
    {
        self::assertSame(['title' => 'ride'], $this->filters()->read(['title' => ' ride '])->values);
    }

    /**
     * A name nobody configured never becomes a column. It is said, by the name
     * the address used, so that whoever sent the link can tell why the list
     * is longer than they expected.
     */
    public function testAFilterNobodyConfiguredIsSetAsideAndSaid(): void
    {
        $reading = $this->filters()->read(['title' => 'ride', 'title) OR (1=1' => 'x']);

        self::assertSame(['title' => 'ride'], $reading->values);
        self::assertCount(1, $reading->setAside);
        self::assertStringContainsString('title) OR (1=1', $reading->setAside[0]);
    }

    /** A name that long is not somebody's filter, and is not repeated back in full either. */
    public function testALongUnknownNameIsShortenedWhereItIsSaid(): void
    {
        $reading = $this->filters()->read([str_repeat('x', 500) => 'y']);

        self::assertCount(1, $reading->setAside);
        self::assertLessThan(200, strlen($reading->setAside[0]));
    }

    public function testAChoiceThatIsNotOfferedIsSetAsideAndSaid(): void
    {
        $reading = $this->filters()->read(['status' => 'bogus']);

        self::assertSame([], $reading->values);
        self::assertCount(1, $reading->setAside);
        self::assertStringContainsString('bogus', $reading->setAside[0]);
        self::assertStringContainsString('Status', $reading->setAside[0]);
    }

    /** `?pages-title[]=x` arrives as an array; it is one value or it is not read. */
    public function testSomethingOtherThanOneValueIsSetAside(): void
    {
        $reading = $this->filters()->read(['title' => ['ride', 'walk']]);

        self::assertSame([], $reading->values);
        self::assertCount(1, $reading->setAside);
        self::assertStringContainsString('Title', $reading->setAside[0]);
    }

    public function testAValueLongerThanAFieldTakesIsSetAside(): void
    {
        $reading = $this->filters()->read(['title' => str_repeat('a', Filter::MAX_LENGTH + 1)]);

        self::assertSame([], $reading->values);
        self::assertCount(1, $reading->setAside);
    }

    public function testAValueAsLongAsAFieldTakesIsRead(): void
    {
        // A character of two bytes, written as an escape so that the source
        // stays ASCII: the limit counts characters, not bytes.
        $value = str_repeat("\u{00FC}", Filter::MAX_LENGTH);

        self::assertSame(['title' => $value], $this->filters()->read(['title' => $value])->values);
    }

    /**
     * A choice keyed by digits comes back from PHP's arrays as a number; what
     * the address carries is a string, and the two are the same choice.
     */
    public function testAChoiceKeyedByDigitsIsReadFromTheAddress(): void
    {
        $filters = new Filters()->choice('year', 'Year', ['2025' => '2025', '2026' => '2026'], Comparison::Equals);

        self::assertSame(['year' => '2026'], $filters->read(['year' => '2026'])->values);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function namesAnAddressCannotCarry(): iterable
    {
        yield 'the page, which the listing keeps for itself' => ['page'];
        yield 'nothing' => [''];
        yield 'a dash, which Nette joins the names of components with' => ['a-b'];
        yield 'a bracket' => ['a[b]'];
        yield 'a digit first' => ['1st'];
    }

    #[DataProvider('namesAnAddressCannotCarry')]
    public function testANameAnAddressCannotCarryIsRefusedWhereItIsWritten(string $name): void
    {
        $this->expectException(\LogicException::class);

        new Filters()->text($name, 'Anything', Comparison::Contains);
    }

    public function testTwoFiltersOfOneNameAreRefused(): void
    {
        $this->expectException(\LogicException::class);

        new Filters()
            ->text('title', 'Title', Comparison::Contains)
            ->text('title', 'Title again', Comparison::Equals);
    }

    public function testAChoiceWithNothingToChooseIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        new Filters()->choice('status', 'Status', [], Comparison::Equals);
    }

    /**
     * The field defaults to the name, and may be said where the two differ -
     * the address says what the person filters by, the field says where it is.
     */
    public function testTheFieldIsTheNameUnlessItIsSaid(): void
    {
        $filters = new Filters()
            ->text('title', 'Title', Comparison::Contains)
            ->text('headline', 'Headline', Comparison::Contains, field: 'title');

        self::assertSame('title', $filters->all()['title']->field);
        self::assertSame('title', $filters->all()['headline']->field);
    }

    /**
     * What a person typed is looked for as it was typed. `%` and `_` mean
     * something to LIKE, so they are escaped - by a character of their own,
     * because a backslash is taken by the server's own escaping in some modes.
     *
     * @return iterable<string, array{Comparison, string, string}>
     */
    public static function boundValues(): iterable
    {
        yield 'equal, as it is' => [Comparison::Equals, '50%_off!', '50%_off!'];
        yield 'contained' => [Comparison::Contains, 'ride', '%ride%'];
        yield 'contained, with what LIKE reads escaped' => [Comparison::Contains, '50%_off!', '%50!%!_off!!%'];
        yield 'at the start' => [Comparison::StartsWith, '50%', '50!%%'];
    }

    #[DataProvider('boundValues')]
    public function testTheBoundValueSaysWhatWasTyped(Comparison $comparison, string $typed, string $bound): void
    {
        self::assertSame($bound, $comparison->bound($typed));
    }

    public function testTheConditionNamesAParameterAndNeverAValue(): void
    {
        self::assertSame('page.title = :filter_title', Comparison::Equals->condition('page.title', 'filter_title'));
        self::assertSame(
            "page.title LIKE :filter_title ESCAPE '!'",
            Comparison::Contains->condition('page.title', 'filter_title'),
        );
    }

    private function filters(): Filters
    {
        return new Filters()
            ->text('title', 'Title', Comparison::Contains)
            ->choice('status', 'Status', ['draft' => 'Draft', 'published' => 'Published'], Comparison::Equals);
    }
}
