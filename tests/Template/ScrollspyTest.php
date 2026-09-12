<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ListGroupItem;

/**
 * c-scrollspy as the server draws it: a navigation, named, holding a
 * c-list-group of links to places on the same page - which is all it is
 * without the script, and a list of contents that works.
 *
 * Which entry is the place being read is assets/scrollspy.ts's, and it is
 * measured in a browser (tests/e2e/scrollspy.spec.ts). The server marks none:
 * it cannot know where the page is scrolled to, and an entry marked in the
 * markup would be a claim about a reader nobody has seen.
 */
#[CoversNothing]
final class ScrollspyTest extends TestCase
{
    public function testItIsANamedNavigationOfLinksToPlacesOnThePage(): void
    {
        $page = ComponentRendering::render(
            'scrollspy.latte',
            "{include scrollspy, label: 'Sections of the notes', items: \$items}",
            ['items' => [
                new ListGroupItem('Finding the site', '#finding'),
                new ListGroupItem('Lifting the slab', '#lifting'),
            ]],
        );

        $nav = $page->querySelector('nav.c-scrollspy');
        self::assertInstanceOf(Element::class, $nav, 'c-scrollspy is not a navigation');
        self::assertSame('Sections of the notes', $nav->getAttribute('aria-label'));

        $list = $nav->querySelector('ul.c-list-group');
        self::assertInstanceOf(Element::class, $list, 'the entries are not a c-list-group');

        $links = [];
        foreach ($list->querySelectorAll('a') as $link) {
            $links[trim($link->textContent ?? '')] = $link->getAttribute('href');
        }
        self::assertSame(['Finding the site' => '#finding', 'Lifting the slab' => '#lifting'], $links);

        self::assertNull($nav->querySelector('[aria-current]'), 'the server marked a place as the one being read');
    }
}
