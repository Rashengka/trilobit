<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-placeholder as markup: the shape of content that is on its way, announced
 * once for the whole block.
 *
 * A skeleton is a dozen empty bars. Each one of them in the accessibility tree
 * is a dozen things to step through that say nothing, and each one a status of
 * its own is a dozen announcements of the same wait. So the bars are hidden,
 * and one status carries the one sentence. Whether the bars pulse, and for whom
 * they do not, is measured in a browser in tests/e2e/placeholder.spec.ts.
 */
#[CoversNothing]
final class PlaceholderTest extends TestCase
{
    public function testTheWholeBlockIsOneStatusWithOneSentence(): void
    {
        $drawn = $this->draw("{include placeholder, label: 'Loading the latest orders…'}");

        $statuses = $drawn->querySelectorAll('[role="status"]');
        self::assertCount(1, $statuses, 'the placeholder announces more than once, or not at all');

        $status = $statuses->item(0);
        self::assertInstanceOf(Element::class, $status);
        self::assertTrue($status->classList->contains('c-placeholder'));
        self::assertSame('Loading the latest orders…', trim((string) $status->textContent));
    }

    public function testEveryShapeIsHiddenFromAssistiveTechnology(): void
    {
        $drawn = $this->draw("{include placeholder, label: 'Loading the latest orders…', lines: 4}");

        $shapes = $drawn->querySelector('.c-placeholder__shapes');
        self::assertInstanceOf(Element::class, $shapes, 'c-placeholder drew no shapes');
        self::assertSame('true', $shapes->getAttribute('aria-hidden'));
        self::assertCount(4, $shapes->querySelectorAll('.c-placeholder__line'));
    }

    /**
     * In place of a card it has the parts of a card - something to look at,
     * a title and a sentence - so that the grid does not jump when the cards
     * arrive.
     */
    public function testInPlaceOfACardItHasThePartsOfACard(): void
    {
        $drawn = $this->draw("{include placeholder, label: 'Loading the products…', shape: 'card'}");

        $placeholder = $drawn->querySelector('.c-placeholder');
        self::assertInstanceOf(Element::class, $placeholder);
        self::assertTrue($placeholder->classList->contains('c-placeholder--card'));
        self::assertNotNull($drawn->querySelector('.c-placeholder__shapes .c-placeholder__media'));
        self::assertNotNull($drawn->querySelector('.c-placeholder__shapes .c-placeholder__heading'));
    }

    public function testLinesOfTextHaveNothingToLookAt(): void
    {
        $drawn = $this->draw("{include placeholder, label: 'Loading the note…'}");

        self::assertNull($drawn->querySelector('.c-placeholder__media'));
        self::assertFalse($drawn->querySelector('.c-placeholder')?->classList->contains('c-placeholder--card') ?? true);
    }

    public function testAShapeNobodyDrewIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->draw("{include placeholder, label: 'Loading…', shape: 'a shape nobody drew'}");
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('placeholder.latte', $call);
    }
}
