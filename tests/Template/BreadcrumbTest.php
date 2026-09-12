<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\Crumb;

/**
 * c-breadcrumb as markup: a navigation of its own, the way up as an ordered
 * list of links, and the page itself at the end of it as the one entry that is
 * not a link.
 *
 * What a browser does with it - that the separator is drawn and not read out -
 * is measured in tests/e2e/components-static.spec.ts.
 */
#[CoversClass(Crumb::class)]
final class BreadcrumbTest extends TestCase
{
    public function testItIsANavigationNamedForWhatItIs(): void
    {
        $nav = $this->nav($this->render());

        self::assertSame('Breadcrumb', $nav->getAttribute('aria-label'));
        self::assertSame('ol', $nav->firstElementChild?->localName, 'the way up is an order, so it is an ordered list');
    }

    public function testEveryStepUpIsALinkInTheOrderGiven(): void
    {
        $items = $this->items($this->render());

        self::assertCount(3, $items);
        self::assertSame(
            [['Home', '/'], ['Catalogue', '/catalogue']],
            array_map(
                static function (Element $item): array {
                    $link = $item->querySelector('a');
                    self::assertNotNull($link);

                    return [trim((string) $link->textContent), $link->getAttribute('href')];
                },
                array_slice($items, 0, 2),
            ),
        );
    }

    /** The page you are on is where the trail ends, and a link to it would lead nowhere new. */
    public function testThePageItselfIsTheLastEntryAndNotALink(): void
    {
        $last = $this->items($this->render())[2];

        self::assertNull($last->querySelector('a'));
        $current = $last->querySelector('[aria-current]');
        self::assertNotNull($current);
        self::assertSame('page', $current->getAttribute('aria-current'));
        self::assertSame('Helmets', trim((string) $current->textContent));
        self::assertCount(1, $this->render()->querySelectorAll('[aria-current]'));
    }

    /**
     * The separator is the stylesheet's, so nothing between the entries is
     * text a screen reader would read between every two of them.
     */
    public function testNoSeparatorIsWrittenIntoTheMarkup(): void
    {
        $words = preg_split('/\s+/', trim((string) $this->nav($this->render())->textContent));

        self::assertSame(['Home', 'Catalogue', 'Helmets'], $words);
    }

    /** Directly under the start there is one link and the page. */
    public function testATrailOfOneStep(): void
    {
        $page = ComponentRendering::render(
            'breadcrumb.latte',
            "{include breadcrumb, trail: \$trail, current: 'Catalogue'}",
            ['trail' => [new Crumb('Home', '/')]],
        );

        self::assertCount(2, $this->items($page));
    }

    private function render(): HTMLDocument
    {
        return ComponentRendering::render(
            'breadcrumb.latte',
            "{include breadcrumb, trail: \$trail, current: 'Helmets', testId: 'trail'}",
            ['trail' => [new Crumb('Home', '/'), new Crumb('Catalogue', '/catalogue')]],
        );
    }

    private function nav(HTMLDocument $page): Element
    {
        $nav = $page->querySelector('nav.c-breadcrumb');
        self::assertNotNull($nav, 'c-breadcrumb drew no nav.c-breadcrumb');
        self::assertSame('trail', $nav->getAttribute('data-testid'));

        return $nav;
    }

    /** @return list<Element> */
    private function items(HTMLDocument $page): array
    {
        return array_values(iterator_to_array($page->querySelectorAll('.c-breadcrumb ol > li')));
    }
}
