<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\DropdownItem;

/**
 * c-dropdown as markup: the menu button of the WAI-ARIA Authoring Practices -
 * a button that says it opens a menu, and the menu it opens, whose entries are
 * each an action or the way somewhere.
 *
 * What the keys do inside the menu, and that the browser opens and closes it, is
 * measured in a browser, in tests/e2e/components-popover.spec.ts.
 */
#[CoversClass(DropdownItem::class)]
final class DropdownTest extends TestCase
{
    public function testTheButtonSaysItOpensTheMenu(): void
    {
        $page = $this->render([new DropdownItem('Duplicate')]);

        $button = $page->querySelector('.c-dropdown > button');
        self::assertNotNull($button, 'c-dropdown drew no button');
        self::assertSame('button', $button->getAttribute('type'));
        self::assertSame('menu', $button->getAttribute('aria-haspopup'));
        self::assertSame('actions', $button->getAttribute('popovertarget'));
        self::assertSame('Actions', trim((string) $button->textContent));
    }

    /**
     * A popover, so the browser opens it, closes it on Escape and on a click
     * outside, and draws it in the top layer; named by the button, so the menu
     * is announced as what the button said.
     */
    public function testTheMenuIsAPopoverNamedByTheButton(): void
    {
        $page = $this->render([new DropdownItem('Duplicate')]);

        $menu = $this->menu($page);
        self::assertSame('auto', $menu->getAttribute('popover') === '' ? 'auto' : $menu->getAttribute('popover'));
        self::assertSame('menu', $menu->getAttribute('role'));
        self::assertSame(
            $page->querySelector('.c-dropdown > button')?->getAttribute('id'),
            $menu->getAttribute('aria-labelledby'),
        );
    }

    /** Every entry is a menuitem left out of the order of Tab: the arrow keys move between them. */
    public function testEveryEntryIsAMenuItemTheArrowsMoveTo(): void
    {
        $menu = $this->menu($this->render([new DropdownItem('Duplicate'), new DropdownItem('Catalogue', '/catalogue')]));

        $entries = $menu->querySelectorAll('.c-dropdown__item');
        self::assertCount(2, $entries);
        foreach ($entries as $entry) {
            self::assertSame('menuitem', $entry->getAttribute('role'));
            self::assertSame('-1', $entry->getAttribute('tabindex'));
        }
    }

    /** An entry with no address is an action and a button; one with an address is a link. */
    public function testAnActionIsAButtonAndADestinationALink(): void
    {
        $menu = $this->menu($this->render([
            new DropdownItem('Duplicate', testId: 'duplicate'),
            new DropdownItem('Catalogue', '/catalogue', testId: 'catalogue'),
        ]));

        $action = $menu->querySelector('[data-testid="duplicate"]');
        self::assertNotNull($action);
        self::assertSame('button', $action->localName);
        self::assertSame('button', $action->getAttribute('type'));

        $link = $menu->querySelector('[data-testid="catalogue"]');
        self::assertNotNull($link);
        self::assertSame('a', $link->localName);
        self::assertSame('/catalogue', $link->getAttribute('href'));
    }

    /** A separated entry has a rule before it; the first entry never does, there being nothing above it to part from. */
    public function testASeparatedEntryIsPartedFromTheOnesBeforeIt(): void
    {
        $menu = $this->menu($this->render([
            new DropdownItem('Duplicate', separated: true),
            new DropdownItem('Move'),
            new DropdownItem('Withdraw', separated: true),
        ]));

        $parts = [];
        for ($child = $menu->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
            $parts[] = $child->localName === 'hr' ? '--' : trim((string) $child->textContent);
        }

        self::assertSame(['Duplicate', 'Move', '--', 'Withdraw'], $parts);
    }

    /** The menu hangs from the dropdown itself, and says which of its edges it lines up with. */
    public function testTheMenuIsAnchoredToTheDropdownAndLinedUpAsAsked(): void
    {
        $start = $this->render([new DropdownItem('Duplicate')]);
        $holder = $start->querySelector('.c-dropdown');
        self::assertNotNull($holder);
        self::assertStringContainsString('anchor-name: --actions', (string) $holder->getAttribute('style'));
        self::assertStringContainsString('position-anchor: --actions', (string) $this->menu($start)->getAttribute('style'));
        self::assertSame('below', $this->menu($start)->getAttribute('data-side'));
        self::assertSame('start', $this->menu($start)->getAttribute('data-align'));

        $end = ComponentRendering::render(
            'dropdown.latte',
            "{include dropdown, id: 'actions', label: 'Actions', items: \$items, align: 'end'}",
            ['items' => [new DropdownItem('Duplicate')]],
        );
        self::assertSame('end', $this->menu($end)->getAttribute('data-align'));
    }

    /** @param list<DropdownItem> $items */
    private function render(array $items): HTMLDocument
    {
        return ComponentRendering::render(
            'dropdown.latte',
            "{include dropdown, id: 'actions', label: 'Actions', items: \$items}",
            ['items' => $items],
        );
    }

    private function menu(HTMLDocument $page): Element
    {
        $menu = $page->querySelector('.c-dropdown__menu');
        self::assertNotNull($menu, 'c-dropdown drew no menu');
        self::assertSame('actions', $menu->getAttribute('id'));
        self::assertTrue($menu->hasAttribute('popover'), 'the menu is not a popover');

        return $menu;
    }
}
