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
        // The element children out of childNodes rather than ->children, which
        // Dom\Element has only from PHP 8.5; :scope > * is not a selector the
        // parser supports.
        foreach ($field->childNodes as $child) {
            if ($child instanceof Element) {
                $order[] = $child->getAttribute('class');
            }
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

    /**
     * A required field carries a mark after its label, and the mark says
     * nothing to a screen reader: the control's own required attribute
     * already does, on the field a caller draws by hand as much as on one
     * this component never sees the control of.
     */
    public function testARequiredFieldCarriesAnAriaHiddenMarkAfterItsLabel(): void
    {
        $field = $this->fieldIn($this->draw(
            '{embed block field, required: true}' . self::LABEL . self::CONTROL . '{/embed}',
        ));

        $mark = $field->querySelector('.c-field__required');
        self::assertNotNull($mark, 'a required field drew no mark');
        self::assertSame('true', $mark->getAttribute('aria-hidden'));
    }

    /** A field nobody said was required carries no mark: one to explain would be one said about nothing. */
    public function testAFieldNotSaidToBeRequiredCarriesNoMark(): void
    {
        $field = $this->fieldIn($this->draw('{embed block field}' . self::LABEL . self::CONTROL . '{/embed}'));

        self::assertNull($field->querySelector('.c-field__required'));
    }

    /**
     * The mark lives inside the element labelHidden hides, so a label taken
     * out of sight takes its mark with it - a mark left standing on its own
     * without the label it belongs to would tell a sighted person nothing.
     */
    public function testARequiredMarkIsHiddenTogetherWithAHiddenLabel(): void
    {
        $field = $this->fieldIn($this->draw(
            '{embed block field, labelHidden: true, required: true}' . self::LABEL . self::CONTROL . '{/embed}',
        ));

        $wrapper = $field->querySelector('.c-field__label');
        self::assertNotNull($wrapper);
        self::assertTrue($wrapper->classList->contains('u-visually-hidden'));
        self::assertNotNull($wrapper->querySelector('.c-field__required'), 'the mark is not inside the hidden wrapper');
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
