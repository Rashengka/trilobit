<?php

declare(strict_types=1);

namespace Trilobit\Tests\Unit\Core\Presentation;

use Nette\Utils\Paginator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Pagination;

/**
 * Which page numbers c-pagination draws, and where each of them leads.
 *
 * The shortening is the part worth a test of its own: it is arithmetic at the
 * edges, and a mistake in it does not stop anything - it draws a row of pages
 * with one missing, or a gap that hides a single number, and both look like a
 * finished page.
 */
#[CoversClass(Pagination::class)]
final class PaginationTest extends TestCase
{
    /**
     * null is a gap: pages left out between the two numbers either side of it.
     *
     * @return iterable<string, array{int, int, int, list<int|null>}>
     */
    public static function windows(): iterable
    {
        yield 'a single page' => [1, 1, 1, [1]];
        yield 'few enough pages to show them all' => [3, 5, 1, [1, 2, 3, 4, 5]];
        yield 'a gap on either side' => [5, 10, 1, [1, null, 4, 5, 6, null, 10]];
        yield 'on the first page' => [1, 10, 1, [1, 2, null, 10]];
        yield 'on the last page' => [10, 10, 1, [1, null, 9, 10]];
        yield 'next to the first page, with nothing to leave out before it' => [3, 10, 1, [1, 2, 3, 4, null, 10]];
        // A gap standing for one page would take as much room as the page and
        // say less, so the page is drawn instead.
        yield 'a gap of one page is the page' => [4, 10, 1, [1, 2, 3, 4, 5, null, 10]];
        yield 'a gap of one page at the end is the page too' => [7, 10, 1, [1, null, 6, 7, 8, 9, 10]];
        yield 'a wider window' => [10, 20, 2, [1, null, 8, 9, 10, 11, 12, null, 20]];
        yield 'no window at all' => [5, 10, 0, [1, null, 5, null, 10]];
    }

    /** @param list<int|null> $expected */
    #[DataProvider('windows')]
    public function testItShortensTheRowOfPages(int $current, int $pageCount, int $around, array $expected): void
    {
        self::assertSame($expected, new Pagination($current, $pageCount, $this->link(...), $around)->pages());
    }

    public function testTheWayBackAndOnFromTheMiddle(): void
    {
        $pagination = new Pagination(5, 10, $this->link(...));

        self::assertSame(1, $pagination->first());
        self::assertSame(4, $pagination->previous());
        self::assertSame(6, $pagination->next());
        self::assertSame(10, $pagination->last());
    }

    /** On the first page there is nowhere back to go, and the component draws that as a step it cannot take. */
    public function testThereIsNoWayBackFromTheFirstPage(): void
    {
        $pagination = new Pagination(1, 10, $this->link(...));

        self::assertNull($pagination->first());
        self::assertNull($pagination->previous());
        self::assertSame(2, $pagination->next());
        self::assertSame(10, $pagination->last());
    }

    public function testThereIsNoWayOnFromTheLastPage(): void
    {
        $pagination = new Pagination(10, 10, $this->link(...));

        self::assertSame(1, $pagination->first());
        self::assertSame(9, $pagination->previous());
        self::assertNull($pagination->next());
        self::assertNull($pagination->last());
    }

    /** The address of a page is the caller's to make, because only the caller knows its router. */
    public function testEveryAddressComesFromTheCallersLink(): void
    {
        self::assertSame('/pages?page=7', new Pagination(5, 10, $this->link(...))->href(7));
    }

    public function testASinglePageIsNothingToPageThrough(): void
    {
        self::assertFalse(new Pagination(1, 1, $this->link(...))->hasMoreThanOnePage());
        self::assertTrue(new Pagination(1, 2, $this->link(...))->hasMoreThanOnePage());
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function impossible(): iterable
    {
        yield 'no pages at all' => [1, 0, 1];
        yield 'a page before the first' => [0, 10, 1];
        yield 'a page after the last' => [11, 10, 1];
        yield 'a window of less than nothing' => [1, 10, -1];
    }

    /**
     * A page outside the row is the caller's mistake, and drawing it anyway
     * would draw a row with no current page - which reads as a row that works.
     * Somebody typing ?page=999 is handled before this, by the paginator that
     * clamps it (see testAPaginatorsClampedPageIsTheCurrentOne).
     */
    #[DataProvider('impossible')]
    public function testAnImpossibleRowIsRefused(int $current, int $pageCount, int $around): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Pagination($current, $pageCount, $this->link(...), $around);
    }

    /**
     * What a listing in the administration already has: Nette's Paginator,
     * which the query takes its offset and limit from. The row is drawn out
     * of the same object, so the page the query read and the page the row
     * marks as current cannot be two different numbers.
     */
    public function testItIsDrawnFromThePaginatorAListingQueriesWith(): void
    {
        $paginator = new Paginator()->setItemsPerPage(20)->setItemCount(195)->setPage(3);

        $pagination = Pagination::fromPaginator($paginator, $this->link(...));

        self::assertSame(3, $pagination->current);
        self::assertSame(10, $pagination->pageCount);
        self::assertSame('/pages?page=4', $pagination->href(4));
    }

    public function testAPaginatorsClampedPageIsTheCurrentOne(): void
    {
        $paginator = new Paginator()->setItemsPerPage(20)->setItemCount(195)->setPage(999);

        self::assertSame(10, Pagination::fromPaginator($paginator, $this->link(...))->current);
    }

    /** An empty listing still has the one page it is shown on. */
    public function testAnEmptyListingHasOnePage(): void
    {
        $paginator = new Paginator()->setItemsPerPage(20)->setItemCount(0);

        $pagination = Pagination::fromPaginator($paginator, $this->link(...));

        self::assertSame(1, $pagination->pageCount);
        self::assertFalse($pagination->hasMoreThanOnePage());
    }

    /** A paginator counting from zero is handed its own numbers back, not ours. */
    public function testAPaginatorCountingFromZeroGetsItsOwnNumbersInTheLink(): void
    {
        $paginator = new Paginator()->setBase(0)->setItemsPerPage(10)->setItemCount(50)->setPage(0);

        $pagination = Pagination::fromPaginator($paginator, $this->link(...));

        self::assertSame(1, $pagination->current);
        self::assertSame(5, $pagination->pageCount);
        self::assertSame('/pages?page=0', $pagination->href(1));
    }

    /** Without a count there is no last page, and guessing one would draw pages that are not there. */
    public function testAPaginatorWithoutACountIsRefused(): void
    {
        $this->expectException(\LogicException::class);

        Pagination::fromPaginator(new Paginator()->setItemsPerPage(20), $this->link(...));
    }

    private function link(int $page): string
    {
        return '/pages?page=' . $page;
    }
}
