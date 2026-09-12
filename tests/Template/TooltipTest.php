<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use Dom\Node;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-tooltip as markup: the element it describes, and the tip that describes it.
 *
 * The element is the caller's, and so is the one attribute that ties it to the
 * tip - aria-describedby, naming the tip's id. That is the one thing a caller can
 * forget without anything looking wrong, so it is asked of every tooltip on
 * every page of the style guide, and so is the other half of the rule: that the
 * tip describes the element and is never all that names it.
 *
 * Showing it on hover and on focus, and putting it away, are measured in a
 * browser, in tests/e2e/components-popover.spec.ts.
 */
#[CoversNothing]
final class TooltipTest extends TestCase
{
    /**
     * A popover the script shows and hides - manual, so that it opens on hover
     * and on focus rather than on a click, and so that showing it closes no
     * menu it sits in.
     */
    public function testTheTipIsAManualPopoverWithTheRoleOfATooltip(): void
    {
        $tip = $this->tip($this->render());

        self::assertSame('manual', $tip->getAttribute('popover'));
        self::assertSame('tooltip', $tip->getAttribute('role'));
        self::assertSame('Keeps a copy.', trim((string) $tip->textContent));
        self::assertSame('above', $tip->getAttribute('data-side'));
    }

    public function testTheElementIsTheCallersAndSitsInTheTooltip(): void
    {
        $page = $this->render();

        $button = $page->querySelector('.c-tooltip > button');
        self::assertNotNull($button, 'the element the caller gave is not inside the tooltip');
        self::assertSame('save', $button->getAttribute('aria-describedby'));
        self::assertStringContainsString('anchor-name: --save', (string) $page->querySelector('.c-tooltip')?->getAttribute('style'));
        self::assertStringContainsString('position-anchor: --save', (string) $this->tip($page)->getAttribute('style'));
    }

    public function testEveryTooltipOnEveryPageDescribesItsElement(): void
    {
        $found = 0;
        foreach (StyleguideSpecimens::everyPage() as $path => $page) {
            foreach ($page->querySelectorAll('.c-tooltip') as $holder) {
                ++$found;
                self::assertSame([], $this->problemsOf($holder), $path);
            }
        }

        self::assertGreaterThan(0, $found, 'no page of the style guide shows a tooltip, so nothing was asked');
    }

    /** The rule above, over tooltips made to break it: a tip nothing refers to, and an element named by nothing else. */
    public function testTheRuleReportsATipNothingRefersToAndAnElementOnlyTheTipNames(): void
    {
        $page = HTMLDocument::createFromString(
            '<!DOCTYPE html><html><body>'
            . '<span class="c-tooltip"><button type="button">Save</button><span id="a" class="c-tooltip__tip" role="tooltip">Keeps a copy.</span></span>'
            . '<span class="c-tooltip"><button type="button" aria-describedby="b"><svg aria-hidden="true"></svg></button><span id="b" class="c-tooltip__tip" role="tooltip">Save</span></span>'
            . '</body></html>',
            LIBXML_NOERROR,
        );

        $holders = iterator_to_array($page->querySelectorAll('.c-tooltip'));
        self::assertCount(2, $holders);
        self::assertSame(['nothing in the tooltip is described by its tip a'], $this->problemsOf($holders[0]));
        self::assertSame(['the element the tip b describes has no name of its own'], $this->problemsOf($holders[1]));
    }

    /** @return list<string> */
    private function problemsOf(Element $holder): array
    {
        $tip = null;
        for ($child = $holder->firstElementChild; $child instanceof Element; $child = $child->nextElementSibling) {
            if ($child->classList->contains('c-tooltip__tip')) {
                $tip = $child;
            }
        }

        if (!$tip instanceof Element) {
            return ['the tooltip has no tip'];
        }

        $id = (string) $tip->getAttribute('id');
        $described = null;
        foreach ($holder->querySelectorAll('[aria-describedby]') as $candidate) {
            $ids = preg_split('/\s+/', trim((string) $candidate->getAttribute('aria-describedby')));
            if (in_array($id, $ids === false ? [] : $ids, true)) {
                $described = $candidate;
            }
        }

        if (!$described instanceof Node) {
            return [sprintf('nothing in the tooltip is described by its tip %s', $id)];
        }

        $name = trim((string) ($described->getAttribute('aria-label') ?? $described->textContent));

        return $name === '' ? [sprintf('the element the tip %s describes has no name of its own', $id)] : [];
    }

    private function render(): HTMLDocument
    {
        return ComponentRendering::render(
            'tooltip.latte',
            "{embed block tooltip, id: 'save', text: 'Keeps a copy.'}{block tooltipTarget}"
            . '<button type="button" aria-describedby="save">Save</button>{/block}{/embed}',
        );
    }

    private function tip(HTMLDocument $page): Element
    {
        $tip = $page->querySelector('.c-tooltip__tip');
        self::assertNotNull($tip, 'c-tooltip drew no tip');
        self::assertSame('save', $tip->getAttribute('id'));

        return $tip;
    }
}
