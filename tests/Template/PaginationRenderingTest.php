<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Pagination;

/**
 * c-pagination as markup: which page is the current one, which steps can be
 * taken, and that a step that cannot be taken is not a link to nowhere.
 *
 * Which numbers are drawn is Trilobit\Tests\Unit\Core\Presentation\PaginationTest;
 * this is what the component does with them.
 */
#[CoversNothing]
final class PaginationRenderingTest extends TestCase
{
    public function testItIsANavigationNamedForWhatItIs(): void
    {
        $nav = $this->render(5, 10)->querySelector('nav.c-pagination');

        self::assertNotNull($nav);
        self::assertSame('Pages', $nav->getAttribute('aria-label'));
        self::assertSame('rows', $nav->getAttribute('data-testid'));
    }

    public function testTheCurrentPageIsMarkedAndIsTheOnlyOne(): void
    {
        $current = $this->render(5, 10)->querySelectorAll('[aria-current]');

        self::assertCount(1, $current);
        $page = $current->item(0);
        self::assertInstanceOf(Element::class, $page);
        self::assertSame('page', $page->getAttribute('aria-current'));
        self::assertSame('5', trim((string) $page->textContent));
    }

    /** "5" on its own does not say five of what. */
    public function testEveryNumberIsNamedAsAPage(): void
    {
        $link = $this->link($this->render(5, 10), '#p6');

        self::assertSame('Page 6', $link->getAttribute('aria-label'));
        self::assertSame('6', trim((string) $link->textContent));
    }

    public function testTheStepsLeadWhereTheyShould(): void
    {
        $page = $this->render(5, 10);

        foreach (['First' => '#p1', 'Previous' => '#p4', 'Next' => '#p6', 'Last' => '#p10'] as $step => $href) {
            self::assertSame($step, trim((string) $this->link($page, $href, $step)->textContent));
        }

        self::assertSame('prev', $this->link($page, '#p4', 'Previous')->getAttribute('rel'));
        self::assertSame('next', $this->link($page, '#p6', 'Next')->getAttribute('rel'));
    }

    /**
     * On the first page the way back is drawn and cannot be taken: not a link
     * with an address, which the keyboard would stop on and which would lead
     * back to where it is, but a link the accessibility tree reports as
     * disabled.
     */
    public function testTheWayBackFromTheFirstPageIsDisabledAndNotALink(): void
    {
        $page = $this->render(1, 10);

        foreach (['First', 'Previous'] as $step) {
            $disabled = $this->step($page, $step);

            self::assertFalse($disabled->hasAttribute('href'), $step . ' has an address on the first page');
            self::assertSame('true', $disabled->getAttribute('aria-disabled'));
            self::assertSame('link', $disabled->getAttribute('role'));
        }

        self::assertTrue($this->step($page, 'Next')->hasAttribute('href'));
    }

    public function testTheWayOnFromTheLastPageIsDisabled(): void
    {
        $page = $this->render(10, 10);

        foreach (['Next', 'Last'] as $step) {
            self::assertFalse($this->step($page, $step)->hasAttribute('href'));
        }
    }

    /** A gap is drawn, and it leads nowhere. */
    public function testAGapIsNotALink(): void
    {
        $gaps = $this->render(5, 10)->querySelectorAll('.c-pagination__gap');

        self::assertCount(2, $gaps);
        foreach ($gaps as $gap) {
            self::assertSame('…', trim((string) $gap->textContent));
            self::assertNull($gap->querySelector('a'));
        }
    }

    public function testTheNumbersAreTheShortenedRow(): void
    {
        $numbers = [];
        foreach ($this->render(5, 10)->querySelectorAll('.c-pagination__item') as $item) {
            $numbers[] = trim((string) $item->textContent);
        }

        self::assertSame(['First', 'Previous', '1', '…', '4', '5', '6', '…', '10', 'Next', 'Last'], $numbers);
    }

    /** One page is nothing to move between, so nothing is drawn rather than a row of disabled steps. */
    public function testASinglePageDrawsNothing(): void
    {
        self::assertNull($this->render(1, 1)->querySelector('.c-pagination'));
    }

    private function render(int $current, int $pageCount): HTMLDocument
    {
        return ComponentRendering::render(
            'pagination.latte',
            "{include pagination, pagination: \$pagination, testId: 'rows'}",
            ['pagination' => new Pagination($current, $pageCount, static fn(int $page): string => '#p' . $page)],
        );
    }

    private function link(HTMLDocument $page, string $href, ?string $text = null): Element
    {
        foreach ($page->querySelectorAll('.c-pagination a[href="' . $href . '"]') as $link) {
            if ($text === null || trim((string) $link->textContent) === $text) {
                return $link;
            }
        }

        self::fail(sprintf('no link to %s%s', $href, $text === null ? '' : ' reading ' . $text));
    }

    private function step(HTMLDocument $page, string $text): Element
    {
        foreach ($page->querySelectorAll('.c-pagination__link') as $step) {
            if (trim((string) $step->textContent) === $text) {
                return $step;
            }
        }

        self::fail('no step reading ' . $text);
    }
}
