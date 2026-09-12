<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-close, and the notice it lets somebody put away.
 *
 * The button is a drawing of a cross and nothing else to look at, so the one
 * thing that can go wrong without anyone seeing it is its name: a screen
 * reader announcing "button", or "times", in place of what it does. What
 * pressing it does to a notice - hides it and puts the focus somewhere that
 * still exists - is measured in a browser, in tests/e2e/components-static.spec.ts.
 */
#[CoversNothing]
final class CloseTest extends TestCase
{
    public function testItIsAButtonNamedForWhatItDoes(): void
    {
        $button = $this->button(ComponentRendering::render('close.latte', "{include close, label: 'Close the basket'}"));

        self::assertSame('button', $button->getAttribute('type'));
        self::assertSame('Close the basket', $button->getAttribute('aria-label'));
    }

    public function testItIsCalledCloseUnlessToldOtherwise(): void
    {
        $button = $this->button(ComponentRendering::render('close.latte', '{include close}'));

        self::assertSame('Close', $button->getAttribute('aria-label'));
    }

    /** The cross is a drawing, silent, and there is no × for a screen reader to read out as "times". */
    public function testTheCrossIsASilentDrawing(): void
    {
        $button = $this->button(ComponentRendering::render('close.latte', '{include close}'));

        $icon = $button->querySelector('svg.c-icon');
        self::assertNotNull($icon);
        self::assertSame('true', $icon->getAttribute('aria-hidden'));
        self::assertSame('', trim((string) $button->textContent));
    }

    public function testANoticeIsNotDismissibleUnlessAskedToBe(): void
    {
        $page = ComponentRendering::render('notice.latte', "{include notice, message: 'Saved.'}");

        $notice = $page->querySelector('.c-notice');
        self::assertNotNull($notice);
        self::assertSame('p', $notice->localName, 'the notice everybody already draws has changed its markup');
        self::assertSame('status', $notice->getAttribute('role'));
        self::assertNull($page->querySelector('.c-close'));
    }

    public function testADismissibleNoticeCarriesTheButton(): void
    {
        $notice = $this->dismissible('info');

        $button = $notice->querySelector('.c-close');
        self::assertNotNull($button);
        self::assertSame('Dismiss', $button->getAttribute('aria-label'));
        self::assertSame('notice', $notice->getAttribute('data-testid'));
        self::assertTrue($notice->classList->contains('c-notice--dismissible'));
    }

    /**
     * The live region is the sentence and not the button: a region holding
     * the button would announce "Saved. Dismiss" every time it appeared.
     */
    public function testOnlyTheSentenceIsAnnounced(): void
    {
        foreach (['info' => 'status', 'danger' => 'alert'] as $variant => $role) {
            $notice = $this->dismissible($variant);

            $region = $notice->querySelector('[role]');
            self::assertNotNull($region);
            self::assertSame($role, $region->getAttribute('role'));
            self::assertSame('Saved.', trim((string) $region->textContent));
            self::assertNull($region->querySelector('.c-close'), 'the button is inside the live region');
            self::assertNull($notice->getAttribute('role'));
        }
    }

    public function testADismissibleRefusalIsStillARefusal(): void
    {
        self::assertTrue($this->dismissible('danger')->classList->contains('c-notice--danger'));
    }

    private function dismissible(string $variant): Element
    {
        $page = ComponentRendering::render(
            'notice.latte',
            "{include notice, message: 'Saved.', variant: \$variant, dismissible: true, testId: 'notice'}",
            ['variant' => $variant],
        );

        $notice = $page->querySelector('.c-notice');
        self::assertNotNull($notice);
        // Without this a notice that ignored the parameter would pass every
        // case above that asks about something it already had.
        self::assertTrue($notice->classList->contains('c-notice--dismissible'), 'the notice was not drawn dismissible');

        return $notice;
    }

    private function button(HTMLDocument $page): Element
    {
        $button = $page->querySelector('button.c-close');
        self::assertNotNull($button, 'c-close drew no button.c-close');

        return $button;
    }
}
