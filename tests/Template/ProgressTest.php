<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-progress as markup: the element the browser already has for each of the
 * two questions, named by its label and saying its value as text.
 *
 * `<progress>` answers "how far has it got", `<meter>` answers "how much is
 * there". The two are announced differently - a progress bar is something that
 * will end, a meter is a reading that stays - so which one a caller gets is the
 * one claim here that matters most, and it is asked of the element and not of
 * a class. How the two are painted, and that nothing moves for somebody who
 * asked for less motion, is measured in a browser in tests/e2e/progress.spec.ts.
 */
#[CoversNothing]
final class ProgressTest extends TestCase
{
    public function testHowFarItHasGotIsAProgressElementNamedByItsLabel(): void
    {
        $drawn = $this->draw("{include progress, label: 'Importing the price list', value: 132, max: 480, text: '132 of 480 rows'}");

        $bar = $this->barIn($drawn);
        self::assertSame('progress', $bar->localName);
        self::assertSame('132', $bar->getAttribute('value'));
        self::assertSame('480', $bar->getAttribute('max'));

        // The label wraps the element, which is what names it; no id to keep unique.
        $label = $bar->closest('label');
        self::assertInstanceOf(Element::class, $label, 'nothing labels the bar');
        self::assertSame('Importing the price list', trim((string) $label->querySelector('.c-progress__label')?->textContent));
    }

    /**
     * The value is said once: as the bar's value text in the accessibility
     * tree, and as the text beside the bar for whoever sees it - which is
     * therefore hidden from the tree, or the number would be read twice.
     */
    public function testTheValueIsSaidAsTextOnce(): void
    {
        $drawn = $this->draw("{include progress, label: 'Importing the price list', value: 132, max: 480, text: '132 of 480 rows'}");

        self::assertSame('132 of 480 rows', $this->barIn($drawn)->getAttribute('aria-valuetext'));

        $shown = $drawn->querySelector('.c-progress__value');
        self::assertInstanceOf(Element::class, $shown, 'the value is not written beside the bar');
        self::assertSame('132 of 480 rows', trim((string) $shown->textContent));
        self::assertSame('true', $shown->getAttribute('aria-hidden'));
    }

    public function testWithoutATextTheValueIsSaidAsAPercentage(): void
    {
        $drawn = $this->draw("{include progress, label: 'Uploading the photographs', value: 1, max: 3}");

        self::assertSame('33%', $this->barIn($drawn)->getAttribute('aria-valuetext'));
        self::assertSame('33%', trim((string) $drawn->querySelector('.c-progress__value')?->textContent));
    }

    /**
     * Not known how far is a progress element without a value - the browser's
     * own indeterminate state, which is what a screen reader reports as busy
     * rather than as nought per cent.
     */
    public function testNotKnownHowFarIsAProgressElementWithoutAValue(): void
    {
        $drawn = $this->draw("{include progress, label: 'Waiting for the carrier to answer'}");

        $bar = $this->barIn($drawn);
        self::assertSame('progress', $bar->localName);
        self::assertFalse($bar->hasAttribute('value'), 'an indeterminate bar carries a value, so it says 0%');
        self::assertFalse($bar->hasAttribute('aria-valuetext'));
        self::assertNull($drawn->querySelector('.c-progress__value'), 'a bar that does not know how far it is says a number');
    }

    public function testHowMuchThereIsIsAMeterWithItsBounds(): void
    {
        $drawn = $this->draw(
            "{include progress, label: 'Drawer B2 filled', value: 46, max: 50, text: '46 of 50 places', measure: true, "
            . 'low: 35, high: 45, optimum: 0}',
        );

        $bar = $this->barIn($drawn);
        self::assertSame('meter', $bar->localName);
        self::assertSame(
            ['min' => '0', 'max' => '50', 'value' => '46', 'low' => '35', 'high' => '45', 'optimum' => '0'],
            [
                'min' => $bar->getAttribute('min'),
                'max' => $bar->getAttribute('max'),
                'value' => $bar->getAttribute('value'),
                'low' => $bar->getAttribute('low'),
                'high' => $bar->getAttribute('high'),
                'optimum' => $bar->getAttribute('optimum'),
            ],
        );
        self::assertSame('46 of 50 places', $bar->getAttribute('aria-valuetext'));
    }

    /** A bound nobody gave is left to the browser rather than written as an empty attribute. */
    public function testAMeterWithoutBoundsCarriesNone(): void
    {
        $bar = $this->barIn($this->draw("{include progress, label: 'Storage used', value: 3, max: 10, measure: true}"));

        self::assertFalse($bar->hasAttribute('low'));
        self::assertFalse($bar->hasAttribute('high'));
        self::assertFalse($bar->hasAttribute('optimum'));
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('progress.latte', $call);
    }

    private function barIn(HTMLDocument $drawn): Element
    {
        $bar = $drawn->querySelector('.c-progress .c-progress__bar');
        self::assertInstanceOf(Element::class, $bar, 'c-progress drew no .c-progress__bar');

        return $bar;
    }
}
