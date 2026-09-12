<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-spinner as markup: a status that says in words what is being waited for,
 * and a shape beside the words that says nothing.
 *
 * A spinner that is only a turning shape tells somebody who cannot see it
 * nothing at all, and tells somebody who asked for less motion nothing either
 * once it stops turning. So the words are the component and the shape is its
 * decoration. When the words are shown and when they are only read out is
 * measured in a browser, in tests/e2e/spinner.spec.ts.
 */
#[CoversNothing]
final class SpinnerTest extends TestCase
{
    public function testItIsAStatusThatSaysWhatIsBeingWaitedFor(): void
    {
        $spinner = $this->spinnerIn($this->draw("{include spinner, label: 'Loading the orders…'}"));

        self::assertSame('status', $spinner->getAttribute('role'));
        self::assertSame('Loading the orders…', trim((string) $spinner->querySelector('.c-spinner__label')?->textContent));
    }

    public function testTheShapeSaysNothing(): void
    {
        $shape = $this->spinnerIn($this->draw("{include spinner, label: 'Loading the orders…'}"))
            ->querySelector('.c-spinner__shape');

        self::assertInstanceOf(Element::class, $shape, 'c-spinner drew no shape');
        self::assertSame('true', $shape->getAttribute('aria-hidden'));
        self::assertSame('', trim((string) $shape->textContent));
    }

    public function testItComesInTheSizeOfTheTextBesideIt(): void
    {
        $spinner = $this->spinnerIn($this->draw("{include spinner, label: 'Saving…', size: 'small'}"));

        self::assertTrue($spinner->classList->contains('c-spinner--small'));
    }

    public function testItsWordsCanBeShownForGood(): void
    {
        $spinner = $this->spinnerIn($this->draw("{include spinner, label: 'Checking the address…', labelShown: true}"));

        self::assertTrue($spinner->classList->contains('c-spinner--labelled'));
        self::assertFalse($this->spinnerIn($this->draw("{include spinner, label: 'Saving…'}"))->classList->contains('c-spinner--labelled'));
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('spinner.latte', $call);
    }

    private function spinnerIn(HTMLDocument $drawn): Element
    {
        $spinner = $drawn->querySelector('.c-spinner');
        self::assertInstanceOf(Element::class, $spinner, 'c-spinner drew no .c-spinner');

        return $spinner;
    }
}
