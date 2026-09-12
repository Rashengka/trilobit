<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Trilobit\Core\Presentation\Component\OffcanvasEdge;

/**
 * c-offcanvas, as the markup the browser is handed: the same dialog as
 * c-modal, drawn against an edge of the window. Which edge is the one thing it
 * adds, and the edge is logical - the start and the end swap sides in a
 * language written from the right - so it is named that way, and nothing else
 * can be asked for. Where it lands is measured in tests/e2e/layers.spec.ts.
 */
#[CoversNothing]
final class OffcanvasTest extends TestCase
{
    /** @return iterable<string, array{OffcanvasEdge}> */
    public static function edges(): iterable
    {
        foreach (OffcanvasEdge::cases() as $edge) {
            yield $edge->value => [$edge];
        }
    }

    public function testTheEdgesAreLogical(): void
    {
        self::assertSame(
            ['start', 'end', 'top', 'bottom'],
            array_map(static fn(OffcanvasEdge $edge): string => $edge->value, OffcanvasEdge::cases()),
        );
    }

    #[DataProvider('edges')]
    public function testItIsAClosedDialogAgainstTheEdgeItWasGiven(OffcanvasEdge $edge): void
    {
        $dialog = $this->dialog(sprintf(
            ', edge: Trilobit\\Core\\Presentation\\Component\\OffcanvasEdge::%s',
            $edge->name,
        ));

        self::assertTrue($dialog->classList->contains('c-offcanvas--' . $edge->value));
        self::assertFalse($dialog->hasAttribute('open'), 'the dialog is drawn open');
    }

    /** The end, which is where a basket or a set of filters is looked for. */
    public function testItIsAgainstTheEndUnlessToldOtherwise(): void
    {
        self::assertTrue($this->dialog()->classList->contains('c-offcanvas--end'));
    }

    public function testItIsNamedByItsHeading(): void
    {
        $dialog = $this->dialog();

        $document = $dialog->ownerDocument;
        self::assertNotNull($document);
        $heading = $document->getElementById((string) $dialog->getAttribute('aria-labelledby'));
        self::assertNotNull($heading, 'aria-labelledby points at nothing');
        self::assertSame('h2', $heading->localName);
        self::assertSame('Basket', trim((string) $heading->textContent));
    }

    /** A panel beside the page is put away by a click on the page, the way a menu is. */
    public function testAClickOutsideItClosesIt(): void
    {
        self::assertSame('any', $this->dialog()->getAttribute('closedby'));
    }

    public function testTheCrossCommandsTheDialogItSitsIn(): void
    {
        $close = $this->dialog()->querySelector('.c-offcanvas__header .c-close');
        self::assertNotNull($close, 'the header carries no c-close');

        self::assertSame('sample', $close->getAttribute('commandfor'));
        self::assertSame('close', $close->getAttribute('command'));
    }

    public function testTheBodyHoldsWhatTheCallerPutsInIt(): void
    {
        self::assertSame('Two specimens.', trim((string) $this->dialog()->querySelector('.c-offcanvas__body')?->textContent));
    }

    private function dialog(string $parameters = ''): Element
    {
        $page = ComponentRendering::render(
            'offcanvas.latte',
            sprintf(
                "{embed block offcanvas, id: 'sample', title: 'Basket'%s}{block offcanvasBody}<p>Two specimens.</p>{/block}{/embed}",
                $parameters,
            ),
        );

        $dialog = $page->querySelector('dialog.c-offcanvas');
        self::assertNotNull($dialog, 'c-offcanvas drew no dialog.c-offcanvas');

        return $dialog;
    }
}
