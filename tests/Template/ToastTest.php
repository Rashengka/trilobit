<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Nette\Utils\FileSystem;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\ComponentRegistry;

/**
 * c-toast: short news drawn over the page, in a corner of the window, one toast
 * for every message - the shape Nette hands its flash messages over in.
 *
 * What a screen reader hears is held here, the way it is for c-notice: the
 * sentence is the live region and the button that puts the toast away is
 * beside it, not in it. What happens in a browser - the toast arriving after a
 * redirect and after a Naja redraw, being announced when it arrives, put away
 * with the focus left somewhere that is still there - is measured in
 * tests/e2e/toast.spec.ts.
 */
#[CoversNothing]
final class ToastTest extends TestCase
{
    private const string COMPONENT = 'c-toast';

    public function testNothingIsDrawnWhenThereIsNothingToSay(): void
    {
        $page = ComponentRendering::render('toast.latte', '{include toast, messages: []}');

        self::assertNull($page->querySelector('.c-toast'));
    }

    public function testEveryMessageIsAToastOfItsOwnInTheOrderItWasGiven(): void
    {
        $toasts = $this->toasts([['First.', 'info'], ['Second.', 'danger'], ['Third.', 'info']]);

        self::assertSame(
            ['First.', 'Second.', 'Third.'],
            array_map(static fn(Element $toast): string => trim($toast->querySelector('.c-toast__message')->textContent ?? ''), $toasts),
        );
    }

    /**
     * The live region is the sentence and not the toast: a region holding the
     * button would announce "Saved. Dismiss" every time a toast arrived.
     */
    public function testOnlyTheSentenceIsAnnouncedAndARefusalInterrupts(): void
    {
        foreach (['info' => 'status', 'danger' => 'alert'] as $type => $role) {
            [$toast] = $this->toasts([['Saved.', $type]]);

            $region = $toast->querySelector('[role]');
            self::assertNotNull($region);
            self::assertSame($role, $region->getAttribute('role'), 'a message of the type ' . $type);
            self::assertSame('Saved.', trim($region->textContent ?? ''));
            self::assertNull($region->querySelector('.c-close'), 'the button is inside the live region');
            self::assertNull($toast->getAttribute('role'));
        }
    }

    public function testEveryToastCarriesTheButtonThatPutsItAway(): void
    {
        foreach ($this->toasts([['First.', 'info'], ['Second.', 'danger']]) as $toast) {
            $button = $toast->querySelector('.c-close');
            self::assertNotNull($button);
            self::assertSame('Dismiss', $button->getAttribute('aria-label'));
        }
    }

    public function testARefusalIsDrawnAsOne(): void
    {
        [$saved, $refused] = $this->toasts([['Saved.', 'info'], ['Refused.', 'danger']]);

        self::assertFalse($saved->classList->contains('c-toast__item--danger'));
        self::assertTrue($refused->classList->contains('c-toast__item--danger'));
    }

    /** In the corner of the window only where the caller asks: a specimen is drawn where it stands. */
    public function testItIsHeldInTheCornerOnlyWhenAskedToBe(): void
    {
        $messages = ['messages' => [(object) ['message' => 'Saved.', 'type' => 'info']]];

        $inPlace = ComponentRendering::render('toast.latte', '{include toast, messages: $messages}', $messages)->querySelector('.c-toast');
        $inTheCorner = ComponentRendering::render('toast.latte', '{include toast, messages: $messages, corner: true}', $messages)->querySelector('.c-toast');

        self::assertNotNull($inPlace);
        self::assertNotNull($inTheCorner);
        self::assertFalse($inPlace->classList->contains('c-toast--corner'));
        self::assertTrue($inTheCorner->classList->contains('c-toast--corner'));
    }

    /**
     * Both layouts draw the flash messages as toasts, inside the snippet the
     * base presenters redraw when a Naja request says something - the same
     * line in both, so that neither chrome can lose it alone.
     */
    public function testBothLayoutsDrawTheFlashMessagesAsToastsInTheSnippetNajaRedraws(): void
    {
        foreach (['Front', 'Admin'] as $chrome) {
            $layout = FileSystem::read(dirname(__DIR__, 2) . '/src/Core/Presentation/' . $chrome . '/templates/@layout.latte');

            self::assertMatchesRegularExpression(
                '~<div n:snippet="flashes">\{include toast, messages: \$flashes, corner: true, testId: \'toasts\'\}</div>~',
                $layout,
                'the ' . $chrome . ' layout does not draw the flash messages as toasts',
            );
        }
    }

    public function testTheStyleGuideShowsEveryVariant(): void
    {
        self::assertSame(
            ['info', 'danger', 'several at once', 'from a flash message'],
            new ComponentRegistry()->find(self::COMPONENT)?->variants,
        );
    }

    /**
     * @param list<array{string, string}> $messages what each says, and its type
     *
     * @return list<Element>
     */
    private function toasts(array $messages): array
    {
        $page = ComponentRendering::render(
            'toast.latte',
            '{include toast, messages: $messages}',
            ['messages' => array_map(static fn(array $message): object => (object) ['message' => $message[0], 'type' => $message[1]], $messages)],
        );

        $toasts = [];
        foreach ($page->querySelectorAll('.c-toast > .c-toast__item') as $toast) {
            $toasts[] = $toast;
        }

        self::assertCount(count($messages), $toasts, 'not one toast for every message');

        return $toasts;
    }
}
