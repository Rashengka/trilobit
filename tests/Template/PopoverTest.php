<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-popover as markup: a button, and the panel it opens with a title and a
 * sentence - a dialog named by its title, which the browser opens and closes.
 *
 * Opening, closing and where it is drawn are measured in a browser, in
 * tests/e2e/components-popover.spec.ts.
 */
#[CoversNothing]
final class PopoverTest extends TestCase
{
    public function testTheButtonOpensThePanel(): void
    {
        $page = $this->render();

        $button = $page->querySelector('.c-popover > button');
        self::assertNotNull($button, 'c-popover drew no button');
        self::assertSame('button', $button->getAttribute('type'));
        self::assertSame('pygidium', $button->getAttribute('popovertarget'));
        self::assertSame('What is it?', trim((string) $button->textContent));
    }

    /**
     * A light-dismiss popover, so a click outside and Escape close it; a
     * dialog, because it is a piece of the page of its own that somebody opened,
     * and named by its title, so it is announced as what it is about.
     */
    public function testThePanelIsADialogNamedByItsTitle(): void
    {
        $panel = $this->panel($this->render());

        self::assertSame('', $panel->getAttribute('popover'), 'the panel is not a light-dismiss popover');
        self::assertSame('dialog', $panel->getAttribute('role'));

        $title = $panel->querySelector('.c-popover__title');
        self::assertNotNull($title);
        self::assertSame($title->getAttribute('id'), $panel->getAttribute('aria-labelledby'));
        self::assertSame('Pygidium', trim((string) $title->textContent));
        self::assertSame('The tail shield.', trim((string) $panel->querySelector('.c-popover__text')?->textContent));
    }

    public function testThePanelHangsFromThePopoverBelowUnlessToldOtherwise(): void
    {
        $page = $this->render();
        self::assertStringContainsString('anchor-name: --pygidium', (string) $page->querySelector('.c-popover')?->getAttribute('style'));
        self::assertStringContainsString('position-anchor: --pygidium', (string) $this->panel($page)->getAttribute('style'));
        self::assertSame('below', $this->panel($page)->getAttribute('data-side'));

        self::assertSame('above', $this->panel($this->render("side: 'above'"))->getAttribute('data-side'));
    }

    private function render(string $more = ''): HTMLDocument
    {
        return ComponentRendering::render(
            'popover.latte',
            "{include popover, id: 'pygidium', label: 'What is it?', title: 'Pygidium', text: 'The tail shield.'"
            . ($more === '' ? '' : ', ' . $more) . '}',
        );
    }

    private function panel(HTMLDocument $page): Element
    {
        $panel = $page->querySelector('.c-popover__panel');
        self::assertNotNull($panel, 'c-popover drew no panel');
        self::assertSame('pygidium', $panel->getAttribute('id'));

        return $panel;
    }
}
