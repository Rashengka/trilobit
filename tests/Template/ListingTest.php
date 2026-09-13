<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Pagination;

/**
 * c-listing as markup: the three things a filtered list can have to say, and
 * that each of them is told apart from the others.
 *
 * "Nothing has been written yet" and "nothing matches these filters" are the
 * pair this is mostly about. Drawn alike, a filter that found nothing looks
 * like a site with no pages, and the one thing that would help - clearing the
 * filters - is not offered. Both are told apart from an error by being a page
 * at all: an error is not drawn by this component.
 */
#[CoversNothing]
final class ListingTest extends TestCase
{
    public function testRowsAreDrawnInTheSlotWithTheWayThroughThemUnder(): void
    {
        $document = $this->draw("state: 'rows', pagination: \$pagination");

        self::assertNotNull($document->querySelector('[data-testid="sample"] table[data-testid="sample-rows"]'));
        self::assertNotNull($document->querySelector('[data-testid="sample-pagination"]'));
        self::assertNull($document->querySelector('[data-testid="sample-empty"]'));
        self::assertNull($document->querySelector('[data-testid="sample-no-match"]'));
    }

    public function testNothingYetIsNotNothingMatching(): void
    {
        $empty = $this->draw("state: 'empty', nothingYet: 'Nothing has been written yet.'");
        $noMatch = $this->draw("state: 'no match', clearUrl: '/pages', nothingMatches: 'No page matches these filters.'");

        self::assertSame('Nothing has been written yet.', trim((string) $empty->querySelector('[data-testid="sample-empty"]')?->textContent));
        self::assertNull($empty->querySelector('[data-testid="sample-clear"]'), 'a list nobody filtered offers to clear its filters');
        self::assertNull($empty->querySelector('table'));

        self::assertStringContainsString(
            'No page matches these filters.',
            (string) $noMatch->querySelector('[data-testid="sample-no-match"]')?->textContent,
        );
        self::assertSame('/pages', $noMatch->querySelector('[data-testid="sample-clear"]')?->getAttribute('href'));
        self::assertNull($noMatch->querySelector('[data-testid="sample-empty"]'));
        self::assertNull($noMatch->querySelector('table'));
    }

    /** A list that is filtered and found something offers the way out of the filter as well. */
    public function testFilteredRowsOfferToClearTheFilters(): void
    {
        $document = $this->draw("state: 'rows', clearUrl: '/pages'");

        self::assertSame('/pages', $document->querySelector('[data-testid="sample-clear"]')?->getAttribute('href'));
    }

    /** Every sentence about a part of the address that was set aside is drawn, each one its own. */
    public function testWhatWasSetAsideIsSaidSentenceBySentence(): void
    {
        $document = $this->draw("state: 'rows', setAside: ['First.', 'Second.']");

        $said = [];
        foreach ($document->querySelectorAll('[data-testid="sample-set-aside"] .c-notice') as $message) {
            $said[] = trim((string) $message->textContent);
        }

        self::assertSame(['First.', 'Second.'], $said);
    }

    /**
     * Redrawn, the links a listing draws ask Naja for the next state rather
     * than loading the page; Naja follows only a link that carries its class.
     * A specimen in the style guide draws them without it, because a link to
     * `#page-5` has no snippet to answer with.
     */
    public function testOnlyARedrawnListingMarksItsLinksForNaja(): void
    {
        $redrawn = $this->draw("state: 'no match', clearUrl: '/pages', redrawn: true");
        $plain = $this->draw("state: 'no match', clearUrl: '/pages'");

        self::assertTrue($redrawn->querySelector('[data-testid="sample-clear"]')?->classList->contains('ajax'));
        self::assertFalse($plain->querySelector('[data-testid="sample-clear"]')?->classList->contains('ajax'));
    }

    public function testARedrawnPaginationMarksEveryLinkForNaja(): void
    {
        $pagination = new Pagination(5, 10, static fn(int $page): string => '#p' . $page);

        $redrawn = ComponentRendering::render('pagination.latte', '{include pagination, pagination: $pagination, redrawn: true}', ['pagination' => $pagination]);
        $plain = ComponentRendering::render('pagination.latte', '{include pagination, pagination: $pagination}', ['pagination' => $pagination]);

        $links = $redrawn->querySelectorAll('.c-pagination a[href]');
        self::assertGreaterThan(0, $links->length);
        foreach ($links as $link) {
            self::assertTrue($link->classList->contains('ajax'), (string) $link->getAttribute('href'));
        }

        self::assertSame(0, $plain->querySelectorAll('.c-pagination .ajax')->length);
    }

    public function testAStateNobodyNamedIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->draw("state: 'loading'");
    }

    private function draw(string $arguments): HTMLDocument
    {
        return ComponentRendering::render(
            'listing.latte',
            sprintf(
                "{embed block listing, %s, testId: 'sample'}{block listingTable}<table data-testid=\"sample-rows\"></table>{/block}{/embed}",
                $arguments,
            ),
            ['pagination' => new Pagination(2, 3, static fn(int $page): string => '#p' . $page)],
        );
    }
}
