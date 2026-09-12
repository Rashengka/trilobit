<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-modal, as the markup the browser is handed.
 *
 * Everything a modal does - taking the focus in, making the page behind it
 * inert, closing on Escape and giving the focus back - is the browser's, and
 * the browser does it only for the markup it expects: a dialog that is closed
 * until it is shown as a modal, named by its heading, with buttons that name
 * it as what they command. That markup is held here; what the browser does
 * with it is measured in tests/e2e/layers.spec.ts.
 */
#[CoversNothing]
final class ModalTest extends TestCase
{
    public function testItIsAClosedDialogNamedByItsHeading(): void
    {
        $dialog = $this->dialog($this->render());

        self::assertSame('sample', $dialog->getAttribute('id'));
        // Drawn open, it would be shown without being modal: nothing behind it
        // inert, no backdrop, and Escape doing nothing.
        self::assertFalse($dialog->hasAttribute('open'), 'the dialog is drawn open');

        $document = $dialog->ownerDocument;
        self::assertNotNull($document);
        $heading = $document->getElementById((string) $dialog->getAttribute('aria-labelledby'));
        self::assertNotNull($heading, 'aria-labelledby points at nothing');
        self::assertSame('h2', $heading->localName);
        self::assertSame('Lend this specimen', trim((string) $heading->textContent));
        self::assertTrue($dialog->contains($heading));
    }

    /** Escape is enough to close it; a click beside it is not, because a half-filled dialog is lost by one. */
    public function testItIsClosedOnlyByAskingToClose(): void
    {
        self::assertFalse($this->dialog($this->render())->hasAttribute('closedby'));
    }

    public function testTheCrossCommandsTheDialogItSitsIn(): void
    {
        $close = $this->dialog($this->render())->querySelector('.c-modal__header .c-close');
        self::assertNotNull($close, 'the header carries no c-close');

        self::assertSame('sample', $close->getAttribute('commandfor'));
        self::assertSame('close', $close->getAttribute('command'));
        self::assertSame('button', $close->getAttribute('type'));
        self::assertSame('Close', $close->getAttribute('aria-label'));
    }

    public function testTheBodyHoldsWhatTheCallerPutsInIt(): void
    {
        $body = $this->dialog($this->render())->querySelector('.c-modal__body');

        self::assertNotNull($body);
        self::assertSame('Who is to have it, and for how long.', trim((string) $body->textContent));
    }

    /** A footer with nothing in it would be a rule and some room around nothing. */
    public function testThereIsAFooterOnlyWhenSomethingIsPutInIt(): void
    {
        self::assertNull($this->dialog($this->render())->querySelector('.c-modal__footer'));

        $footer = $this->dialog($this->render('{block modalFooter}<button type="button">Lend it</button>{/block}'))
            ->querySelector('.c-modal__footer');
        self::assertNotNull($footer);
        self::assertNotNull($footer->querySelector('button'));
    }

    public function testItIsNotAFormUnlessAskedToBe(): void
    {
        $dialog = $this->dialog($this->render());

        self::assertNull($dialog->querySelector('form'));
        self::assertSame('div', $dialog->querySelector('.c-modal__frame')?->localName);
    }

    /**
     * Asked to be one, everything in it is inside a form the browser closes
     * the dialog with: a submit button in it closes it and leaves its value
     * as the dialog's answer, with no script at all.
     */
    public function testAsAFormEverythingIsInsideAFormThatClosesIt(): void
    {
        $dialog = $this->dialog($this->render(
            '{block modalFooter}<button type="submit" value="lend">Lend it</button>{/block}',
            'dialogForm: true',
        ));

        $form = $dialog->querySelector('.c-modal__frame');
        self::assertNotNull($form);
        self::assertSame('form', $form->localName);
        self::assertSame('dialog', $form->getAttribute('method'));
        self::assertSame($dialog, $form->parentElement);

        foreach (['.c-modal__header', '.c-modal__body', '.c-modal__footer'] as $part) {
            self::assertNotNull($form->querySelector($part), $part . ' is outside the form');
        }
    }

    public function testItCarriesTheTestId(): void
    {
        self::assertSame('lend', $this->dialog($this->render('', "testId: 'lend'"))->getAttribute('data-testid'));
    }

    private function render(string $blocks = '', string $parameters = ''): HTMLDocument
    {
        return ComponentRendering::render(
            'modal.latte',
            sprintf(
                "{embed block modal, id: 'sample', title: 'Lend this specimen'%s}"
                . '{block modalBody}<p>Who is to have it, and for how long.</p>{/block}%s{/embed}',
                $parameters === '' ? '' : ', ' . $parameters,
                $blocks,
            ),
        );
    }

    private function dialog(HTMLDocument $page): Element
    {
        $dialog = $page->querySelector('dialog.c-modal');
        self::assertNotNull($dialog, 'c-modal drew no dialog.c-modal');

        return $dialog;
    }
}
