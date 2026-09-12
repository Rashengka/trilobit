<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-card's footer: the line under a tile that says what it costs, or when it
 * was last changed - the thing a shop's listing and an administration's list
 * of pages both put at the foot of a tile.
 *
 * The footer is part of the tile and not of its link. The link is named by the
 * title alone, and a price read out as part of every product's name would make
 * a list of links nobody can tell apart by their beginnings.
 */
#[CoversNothing]
final class CardTest extends TestCase
{
    public function testTheFooterIsATileOfItsOwnAndNotPartOfTheLink(): void
    {
        $drawn = $this->draw(
            "{include card, title: 'Ammonite mug', href: '#', text: 'Stoneware.', footer: '24.00 EUR, in stock'}",
        );

        $footer = $drawn->querySelector('.c-card > .c-card__footer');
        self::assertInstanceOf(Element::class, $footer, 'c-card drew no footer');
        self::assertSame('24.00 EUR, in stock', trim((string) $footer->textContent));
        self::assertNull($footer->closest('a'), 'the footer is inside the link, so it is read as part of the title');

        $links = $drawn->querySelectorAll('.c-card a');
        self::assertCount(1, $links, 'a tile is one link');
        self::assertSame('Ammonite mug', trim((string) $links->item(0)?->textContent));
    }

    public function testWithoutAFooterThereIsNoEmptyOne(): void
    {
        self::assertNull($this->draw("{include card, title: 'Ammonite mug', href: '#'}")->querySelector('.c-card__footer'));
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('card.latte', $call);
    }
}
