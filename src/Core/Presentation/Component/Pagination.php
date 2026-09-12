<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

use Nette\Utils\Paginator;

/**
 * The row of pages c-pagination draws: which page is current, which numbers are
 * shown, and where each of them leads.
 *
 * **The numbers are decided here and not in the template,** because shortening a
 * long row is arithmetic at the edges and a mistake in it stops nothing: it
 * draws a row with a page missing, or a gap that hides a single number, and
 * both look finished. Here it has a unit test
 * (tests/Unit/Core/Presentation/PaginationTest); in a template it would have a
 * page somebody happens to look at.
 *
 * **The addresses are the caller's.** It is handed a link - a closure from a
 * page number to an address - because only the caller knows its router and its
 * parameters: a listing in the administration passes
 * `fn(int $page): string => $this->link('this', ['page' => $page])`, and a
 * filter the listing carries in the same URL stays in it. Nothing here builds a
 * query string.
 *
 * **A listing builds it from the paginator it queries with** (fromPaginator()),
 * so the page the query read and the page the row marks as current are one
 * number rather than two that happen to agree. Nette's Paginator is what
 * .ai/plans/15 will hold the offset and the limit in; it is already a
 * dependency, and it clamps a page somebody typed out of range.
 *
 * Pages are counted from 1 here whatever the paginator counts from; the link is
 * handed the paginator's own number back.
 */
final readonly class Pagination
{
    /**
     * @param int $current the page being shown, from 1
     * @param int $pageCount how many pages there are, at least 1
     * @param \Closure(int): string $link the address of a page, by its number
     * @param int $around how many pages either side of the current one are
     *     drawn; the first and the last are drawn always
     */
    public function __construct(
        public int $current,
        public int $pageCount,
        private \Closure $link,
        public int $around = 1,
    ) {
        if ($pageCount < 1) {
            throw new \InvalidArgumentException(sprintf('A row of pages has at least one page, not %d.', $pageCount));
        }

        if ($current < 1 || $current > $pageCount) {
            throw new \InvalidArgumentException(sprintf('Page %d is not one of the %d pages.', $current, $pageCount));
        }

        if ($around < 0) {
            throw new \InvalidArgumentException(sprintf('No window is %d pages wide.', $around));
        }
    }

    /**
     * A listing that has no count yet has no last page, and is refused rather
     * than drawn with one guessed.
     *
     * @param \Closure(int): string $link the address of a page, by the
     *     paginator's own number for it
     */
    public static function fromPaginator(Paginator $paginator, \Closure $link, int $around = 1): self
    {
        $count = $paginator->getPageCount();
        if ($count === null) {
            throw new \LogicException('The paginator has no item count, so there is no last page to draw.');
        }

        $base = $paginator->getBase();

        return new self(
            $paginator->getPage() - $base + 1,
            // An empty listing has no pages to Nette and one page to whoever
            // is looking at it.
            max(1, $count),
            static fn(int $page): string => $link($page + $base - 1),
            $around,
        );
    }

    /**
     * The numbers to draw, in order, with null where pages are left out.
     *
     * The first page, the last page and the window around the current one are
     * drawn. A gap that would stand for one page is drawn as that page, because
     * it would take as much room and say less.
     *
     * @return list<int|null>
     */
    public function pages(): array
    {
        $shown = range(max(1, $this->current - $this->around), min($this->pageCount, $this->current + $this->around));
        $shown = array_unique([1, ...$shown, $this->pageCount]);
        sort($shown);

        $row = [];
        $previous = null;
        foreach ($shown as $page) {
            if ($previous !== null && $page - $previous === 2) {
                $row[] = $previous + 1;
            } elseif ($previous !== null && $page - $previous > 2) {
                $row[] = null;
            }

            $row[] = $page;
            $previous = $page;
        }

        return $row;
    }

    /** The first page, unless it is the one being shown. */
    public function first(): ?int
    {
        return $this->current === 1 ? null : 1;
    }

    public function previous(): ?int
    {
        return $this->current === 1 ? null : $this->current - 1;
    }

    public function next(): ?int
    {
        return $this->current === $this->pageCount ? null : $this->current + 1;
    }

    /** The last page, unless it is the one being shown. */
    public function last(): ?int
    {
        return $this->current === $this->pageCount ? null : $this->pageCount;
    }

    public function href(int $page): string
    {
        return ($this->link)($page);
    }

    /** One page is nothing to move between, and c-pagination draws nothing for it. */
    public function hasMoreThanOnePage(): bool
    {
        return $this->pageCount > 1;
    }
}
