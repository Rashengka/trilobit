<?php

declare(strict_types=1);

namespace Trilobit\Tests\Template;

use Dom\Element;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * c-field carries the sentence under a control - what it is for, and why it
 * was refused - and draws neither when there is nothing to say.
 *
 * An empty paragraph under every control would be a gap nobody asked for in
 * every form, and a reason with no id would be a sentence a screen reader
 * never connects to the control it is about. So the two are drawn only when
 * filled in, and each carries the id it was given, for the control's
 * aria-describedby to point at.
 */
#[CoversNothing]
final class FieldTest extends TestCase
{
    private const string LABEL = '{block fieldLabel}<label for="sample-town">Town</label>{/block}';

    private const string CONTROL = '{block fieldControl}<input id="sample-town">{/block}';

    public function testAFieldWithNothingToSayDrawsNoSentenceUnderTheControl(): void
    {
        $field = $this->fieldIn($this->draw('{embed block field}' . self::LABEL . self::CONTROL . '{/embed}'));

        self::assertNull($field->querySelector('.c-field__hint'));
        self::assertNull($field->querySelector('.c-field__error'));
    }

    public function testTheHintAndTheReasonAreDrawnUnderTheControlWithTheirIds(): void
    {
        $field = $this->fieldIn($this->draw(
            "{embed block field, hintId: 'sample-hint', errorId: 'sample-error'}"
            . self::LABEL
            . self::CONTROL
            . '{block fieldHint}As it is written on the label of the drawer.{/block}'
            . '{block fieldError}This town is not in the register.{/block}'
            . '{/embed}',
        ));

        $hint = $field->querySelector('.c-field__hint');
        self::assertNotNull($hint, 'the hint was given and not drawn');
        self::assertSame('sample-hint', $hint->getAttribute('id'));
        self::assertSame('As it is written on the label of the drawer.', trim($hint->textContent ?? ''));

        $error = $field->querySelector('.c-field__error');
        self::assertNotNull($error, 'the reason was given and not drawn');
        self::assertSame('sample-error', $error->getAttribute('id'));
        self::assertSame('This town is not in the register.', trim($error->textContent ?? ''));

        // Under the control, in the order a reader meets them: what went wrong
        // first, what the field is for after it.
        $order = [];
        // :scope > * rather than ->children, which Dom\Element has only from PHP 8.5.
        foreach ($field->querySelectorAll(':scope > *') as $child) {
            $order[] = $child->getAttribute('class');
        }

        self::assertSame(['c-field__label', 'c-field__control', 'c-field__error', 'c-field__hint'], $order);
    }

    /** An id nobody asked for is not invented: an empty one would be worse than none. */
    public function testASentenceWithNoIdGivenCarriesNone(): void
    {
        $field = $this->fieldIn($this->draw(
            '{embed block field}' . self::LABEL . self::CONTROL . '{block fieldHint}Any town will do.{/block}{/embed}',
        ));

        self::assertFalse($field->querySelector('.c-field__hint')?->hasAttribute('id') ?? true);
    }

    private function draw(string $call): HTMLDocument
    {
        return ComponentRendering::render('field.latte', $call);
    }

    private function fieldIn(HTMLDocument $document): Element
    {
        $field = $document->querySelector('.c-field');
        self::assertNotNull($field, 'c-field drew nothing');

        return $field;
    }
}
