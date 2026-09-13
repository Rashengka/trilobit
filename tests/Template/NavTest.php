<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Front\Navigation\NavigationItem;

/**
 * c-nav with entries under an entry, as markup: what every theme is handed,
 * whatever it then does with it.
 *
 * The one requirement a submenu breaks most often is asked first and most
 * strictly: an entry that has entries under it still leads somewhere, and what
 * opens the entries under it is a control of its own beside the link, never the
 * link itself (.ai/plans/10-menu-submenu-a-rozcestniky.md, M1). On a touch
 * screen there is no hover, and one surface asked to do two things always does
 * one of them.
 *
 * The rest is what a browser needs to open it for somebody who cannot see it:
 * the button says whether it is open and what it opens, and what it opens is
 * there to be found by that name. How the entries under an entry look and when
 * they open is measured in a browser, in tests/e2e/nav.spec.ts.
 */
#[CoversNothing]
final class NavTest extends TestCase
{
    public function testAnEntryWithEntriesUnderItStillLinksToItsOwnAddress(): void
    {
        $page = $this->render();

        $link = $this->byTestId($page, 'shop');
        self::assertSame('a', $link->localName);
        self::assertSame('/shop', $link->getAttribute('href'));
        self::assertNull($link->closest('button'), 'the link to the entry is inside the button that opens it');
    }

    public function testWhatOpensTheEntriesUnderItIsAButtonBesideTheLink(): void
    {
        $entry = $this->entryOf($this->render(), 'shop');

        $buttons = $this->childrenOf($entry, 'button');
        self::assertCount(1, $buttons, 'an entry with entries under it has exactly one button of its own');
        self::assertSame('button', $buttons[0]->getAttribute('type'));
        self::assertNull($buttons[0]->querySelector('a'), 'the button holds a link, so one surface does two things');
        self::assertSame(
            'shop-toggle',
            $buttons[0]->getAttribute('data-testid'),
        );
        self::assertStringContainsString(
            'Shop',
            (string) $buttons[0]->textContent,
            'the button does not name the entry it opens, so a screen reader announces a row of identical buttons',
        );
    }

    public function testTheButtonSaysItIsClosedAndNamesTheListItOpens(): void
    {
        $page = $this->render();
        $button = $this->childrenOf($this->entryOf($page, 'shop'), 'button')[0];

        self::assertSame('false', $button->getAttribute('aria-expanded'));

        $controlled = $page->getElementById((string) $button->getAttribute('aria-controls'));
        self::assertInstanceOf(Element::class, $controlled, 'aria-controls names nothing on the page');
        self::assertSame($this->entryOf($page, 'shop'), $controlled->parentElement, 'the button opens a list that is not its own entry\'s');

        self::assertSame(
            ['bicycles', 'helmets'],
            array_map(
                fn(Element $entry): ?string => $this->childrenOf($entry, 'a')[0]->getAttribute('data-testid'),
                $this->childrenOf($controlled, 'li'),
            ),
        );
    }

    /** Drawn as deep as the tree goes; how much of it a theme shows is the theme's to decide. */
    public function testEveryLevelOfTheTreeIsDrawnAndEveryParentHasItsButton(): void
    {
        $page = $this->render();

        foreach (['shop' => true, 'bicycles' => true, 'mountain' => true, 'hardtail' => false, 'home' => false, 'helmets' => false] as $id => $hasEntries) {
            $entry = $this->entryOf($page, $id);

            self::assertCount($hasEntries ? 1 : 0, $this->childrenOf($entry, 'button'), $id . ': wrong number of buttons');
            self::assertCount($hasEntries ? 1 : 0, $this->childrenOf($entry, 'ul'), $id . ': wrong number of lists under it');
        }
    }

    /** Two entries opening lists by the same name would open each other's. */
    public function testEveryListOpenedHasAnIdOfItsOwn(): void
    {
        $page = $this->render();

        $ids = [];
        foreach ($page->querySelectorAll('button[aria-controls]') as $button) {
            $ids[] = (string) $button->getAttribute('aria-controls');
        }

        // The three entries with entries under them, and the Menu button the
        // whole list may be folded behind.
        self::assertCount(4, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /** The entries under an entry are part of the same navigation, not a navigation of their own. */
    public function testTheWholeTreeIsOneNavigation(): void
    {
        $page = $this->render();

        self::assertCount(1, $page->querySelectorAll('nav'));
        self::assertCount(1, $page->querySelectorAll('.c-nav__list'));
    }

    /**
     * The button a theme may fold the whole navigation behind
     * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M1: ledger on a narrow
     * window). It is drawn in every theme and at every width, and it is the
     * stylesheet that decides whether it is shown - so what is asked here is
     * only that it is a disclosure that says what it opens.
     */
    public function testTheNavigationHasOneMenuButtonBeforeItsListAndNamingIt(): void
    {
        $page = $this->render();
        $nav = $page->querySelector('nav');
        self::assertInstanceOf(Element::class, $nav);

        $buttons = $this->childrenOf($nav, 'button');
        self::assertCount(1, $buttons, 'the navigation itself has exactly one button of its own');
        $button = $buttons[0];

        self::assertSame('button', $button->getAttribute('type'));
        self::assertSame('Menu', trim((string) $button->textContent));
        self::assertSame(
            'false',
            $button->getAttribute('aria-expanded'),
            'folded is the state the stylesheet may show; open would leave nothing to fold',
        );

        $list = $page->getElementById((string) $button->getAttribute('aria-controls'));
        self::assertInstanceOf(Element::class, $list, 'aria-controls names nothing on the page');
        self::assertSame(
            $list,
            $button->nextElementSibling,
            'the list the button opens does not follow it, and that is what the stylesheet folds it by',
        );
        self::assertStringContainsString('c-nav__list', (string) $list->getAttribute('class'));
    }

    /** Two navigations on one page would otherwise fold each other's list. */
    public function testTwoNavigationsOnOnePageFoldListsOfTheirOwn(): void
    {
        $page = ComponentRendering::render(
            'nav.latte',
            "{include nav, items: \$items, label: 'Primary', testId: 'layout-nav'}\n{include nav, items: \$items, label: 'Pages'}",
            ['items' => [new NavigationItem('Home', '/', true, 'home')]],
        );

        self::assertCount(1, $page->querySelectorAll('[data-testid="layout-nav-menu"]'), 'the button is not named after its navigation');

        $ids = [];
        foreach ($page->querySelectorAll('.c-nav__menu') as $button) {
            $ids[] = (string) $button->getAttribute('aria-controls');
        }

        self::assertCount(2, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
        foreach ($ids as $id) {
            self::assertInstanceOf(Element::class, $page->getElementById($id), sprintf('%s names nothing on the page', $id));
        }
    }

    /**
     * Every entry above the current page says the page is under it, and only
     * those do. It is what ledger unfolds by itself and what atrium marks
     * without opening anything (.ai/plans/10-menu-submenu-a-rozcestniky.md,
     * M1, decided 2026-09-13) - and the server says it, so the mark is there
     * without the script.
     */
    public function testTheEntriesAboveTheCurrentPageSayItIsUnderThem(): void
    {
        $page = $this->render('mountain');

        $expected = ['home' => null, 'shop' => 'true', 'bicycles' => 'true', 'mountain' => 'page', 'hardtail' => null, 'helmets' => null];
        foreach ($expected as $id => $current) {
            self::assertSame($current, $this->byTestId($page, $id)->getAttribute('aria-current'), $id . ': wrong aria-current');
        }
    }

    public function testNoEntryIsMarkedWhereTheCurrentPageIsNotInTheNavigation(): void
    {
        self::assertCount(0, $this->render(null)->querySelectorAll('[aria-current]'));
    }

    /** @param ?string $current the testid of the entry for the page being drawn; the home page unless said */
    private function render(?string $current = 'home'): HTMLDocument
    {
        $entry = static fn(string $label, string $href, string $id, NavigationItem ...$children): NavigationItem => new NavigationItem(
            $label,
            $href,
            $id === $current,
            $id,
            array_values($children),
        );

        return ComponentRendering::render(
            'nav.latte',
            "{include nav, items: \$items, label: 'Primary'}",
            ['items' => [
                $entry('Home', '/', 'home'),
                $entry(
                    'Shop',
                    '/shop',
                    'shop',
                    $entry(
                        'Bicycles',
                        '/shop/bicycles',
                        'bicycles',
                        $entry('Mountain', '/shop/bicycles/mountain', 'mountain', $entry('Hardtail', '/shop/bicycles/mountain/hardtail', 'hardtail')),
                    ),
                    $entry('Helmets', '/shop/helmets', 'helmets'),
                ),
            ]],
        );
    }

    private function byTestId(HTMLDocument $page, string $id): Element
    {
        $element = $page->querySelector(sprintf('[data-testid="%s"]', $id));
        self::assertInstanceOf(Element::class, $element, sprintf('nothing carries the testid %s', $id));

        return $element;
    }

    /** The list item the link carrying $id is an entry of. */
    private function entryOf(HTMLDocument $page, string $id): Element
    {
        $entry = $this->byTestId($page, $id)->parentElement;
        self::assertInstanceOf(Element::class, $entry);
        self::assertSame('li', $entry->localName, sprintf('the link %s is not directly an entry of a list', $id));

        return $entry;
    }

    /**
     * The direct children of $parent that are $name elements.
     *
     * Read out of childNodes rather than ->children, which Dom\Element has
     * only from PHP 8.5 on, and this project still runs on 8.4.
     *
     * @return list<Element>
     */
    private function childrenOf(Element $parent, string $name): array
    {
        $found = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof Element && $child->localName === $name) {
                $found[] = $child;
            }
        }

        return $found;
    }
}
