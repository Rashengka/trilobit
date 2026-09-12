<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The variants of c-button a page of this application has a use for: the one
 * that deletes, the smaller one in a row of a table, and the one that is only
 * an icon and still has a name.
 *
 * Only the markup is asked here. That the danger variant can be read in both
 * themes and both modes, and that the icon-only one is reached by the keyboard
 * under its name, is measured in a browser in tests/e2e/component-variants.spec.ts.
 */
#[CoversNothing]
final class ButtonTest extends TestCase
{
    public function testTheOneThatDeletesSaysSoByItsVariant(): void
    {
        $button = $this->buttonIn($this->draw("{include button, label: 'Delete this page', variant: 'danger'}"));

        self::assertTrue($button->classList->contains('c-button--danger'));
        self::assertFalse($button->classList->contains('c-button--quiet'));
    }

    public function testTheSmallerOneIsASizeAndNotAVariant(): void
    {
        $button = $this->buttonIn($this->draw("{include button, label: 'Open', href: '#', variant: 'quiet', size: 'small'}"));

        self::assertTrue($button->classList->contains('c-button--small'));
        self::assertTrue($button->classList->contains('c-button--quiet'), 'a size took the place of the variant');
    }

    /**
     * Only an icon on the page, and the label still in the button: as text a
     * screen reader reads and a translation reaches, rather than as an
     * attribute that neither of them is sure to.
     */
    public function testWithOnlyAnIconTheLabelIsStillItsName(): void
    {
        $button = $this->buttonIn(
            $this->draw("{include button, label: 'Sign out', href: '#', variant: 'quiet', icon: 'sign-out', iconOnly: true}"),
        );

        self::assertTrue($button->classList->contains('c-button--icon-only'));
        self::assertSame('Sign out', trim((string) $button->textContent));

        $label = $button->querySelector('.u-visually-hidden');
        self::assertInstanceOf(Element::class, $label, 'the label is not in the button');
        self::assertSame('Sign out', trim((string) $label->textContent));
        self::assertSame('true', $button->querySelector('svg.c-icon')?->getAttribute('aria-hidden'));
    }

    /** Without an icon, "only an icon" would be a button with nothing on it. */
    public function testOnlyAnIconWithoutAnIconIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->draw("{include button, label: 'Sign out', iconOnly: true}");
    }

    public function testTheDefaultIsTheOneItAlwaysWas(): void
    {
        $button = $this->buttonIn($this->draw("{include button, label: 'Add to basket', href: '#'}"));

        self::assertSame('c-button', $button->getAttribute('class'));
        self::assertSame('Add to basket', trim((string) $button->textContent));
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('button.latte', $call);
    }

    private function buttonIn(HTMLDocument $drawn): Element
    {
        $button = $drawn->querySelector('.c-button');
        self::assertInstanceOf(Element::class, $button, 'c-button drew no .c-button');

        return $button;
    }
}
