<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Controls\Button;
use Nette\Forms\Controls\Checkbox;
use Nette\Forms\Controls\CheckboxList;
use Nette\Forms\Controls\RadioList;
use Nette\HtmlStringable;
use Nette\Utils\Html;

/**
 * One control of a form drawn in an arrangement, handed to the arrangement's
 * template: the label, the control, and the two sentences that may go under it,
 * with the ids that join them.
 *
 * It gives the template elements, never markup around them. The label and the
 * control are the framework's own - the same elements n:name draws in a form
 * written by hand - and where they go is the template's and c-field's
 * business. What this adds to them is what an arrangement must not get wrong
 * and must not do three times: the control names its reasons and its hint in
 * aria-describedby, reasons first, and says it was refused in aria-invalid.
 *
 * Four kinds of control are drawn differently, and $kind says which:
 *
 * - line: anything with a label of its own pointing at it - every kind of
 *   input, a select, a textarea;
 * - check: a box to tick, drawn inside its own label the way nette/forms and
 *   assets/base.css expect, so it has no label beside it;
 * - radios and checks: a set of choices, each drawn inside its label, and the
 *   set named as a whole by the element labelId() is on (choices());
 * - button: drawn as a <button>, so that it can carry the design system's
 *   button the way the sign-in page's does.
 */
final readonly class FormField
{
    /** @var 'line'|'check'|'radios'|'checks'|'button' */
    public string $kind;

    public function __construct(private BaseControl $control)
    {
        $this->kind = match (true) {
            $control instanceof Button => 'button',
            $control instanceof Checkbox => 'check',
            $control instanceof RadioList => 'radios',
            $control instanceof CheckboxList => 'checks',
            default => 'line',
        };
    }

    /** The name of the control in its form, which is not always the name it is sent under. */
    public function name(): string
    {
        return (string) $this->control->getName();
    }

    /**
     * What the control is called: a label pointing at it, or - for a set of
     * choices - an element the set is named by. Nothing for a box to tick and a
     * button, whose words are part of the control itself.
     */
    public function label(): ?Html
    {
        if ($this->kind === 'check' || $this->kind === 'button') {
            return null;
        }

        $label = $this->control->getLabel();
        if ($label === null) {
            return null;
        }

        if (!$label instanceof Html) {
            $label = Html::el('label')->setText($label);
        }

        // A label points at one control, and a set of choices is several: the
        // set is named by this element through aria-labelledby instead.
        if ($this->kind === 'radios' || $this->kind === 'checks') {
            return $label->setName('span')->setAttribute('for', null)->setAttribute('id', $this->labelId());
        }

        return $label->setAttribute('for', $this->id());
    }

    /** The id of the element a set of choices is named by. */
    public function labelId(): string
    {
        return $this->id() . '-label';
    }

    /** The control, joined to what is said about it. Not for a set of choices, which is drawn by choices(). */
    public function control(): Html
    {
        $control = $this->control;

        if ($control instanceof Checkbox) {
            return $control->getLabelPart()->insert(0, $this->described($control->getControlPart()));
        }

        if ($control instanceof RadioList || $control instanceof CheckboxList) {
            throw new \LogicException(sprintf(
                '%s is a set of choices and is drawn one choice at a time, by choices().',
                $this->name(),
            ));
        }

        if ($control instanceof Button) {
            return $this->asButton($control->getControl());
        }

        $element = $control->getControl();
        if (!$element instanceof Html) {
            throw new \LogicException(sprintf('%s did not draw itself as an element.', $this->name()));
        }

        return $this->described($element);
    }

    /**
     * Every choice of a set, each drawn inside its own label, and each joined
     * to what is said about the set.
     *
     * @return list<Html>
     */
    public function choices(): array
    {
        $control = $this->control;
        if (!$control instanceof RadioList && !$control instanceof CheckboxList) {
            throw new \LogicException(sprintf('%s is not a set of choices.', $this->name()));
        }

        $choices = [];
        foreach (array_keys($control->getItems()) as $key) {
            $choices[] = $control->getLabelPart($key)->insert(0, $this->described($control->getControlPart($key)));
        }

        return $choices;
    }

    /** @return list<string> why the answer was refused */
    public function errors(): array
    {
        return array_map(strval(...), $this->control->getErrors());
    }

    /** The id of the sentence saying why, or null where nothing was refused. */
    public function errorId(): ?string
    {
        return $this->control->hasErrors() ? $this->id() . '-error' : null;
    }

    /** What the field is for: the control's description option, as nette/forms names it. */
    public function hint(): string|HtmlStringable|null
    {
        $description = $this->control->getOption('description');
        if ($description instanceof HtmlStringable) {
            return $description;
        }

        if (!is_string($description) && !$description instanceof \Stringable) {
            return null;
        }

        $translated = $this->control->translate((string) $description);
        $hint = is_string($translated) ? $translated : (string) $description;

        return $hint === '' ? null : $hint;
    }

    /** The id of the hint, or null where there is none. */
    public function hintId(): ?string
    {
        return $this->hint() !== null ? $this->id() . '-hint' : null;
    }

    /** Whether the template drew the control - which the framework notes on the control as it draws it. */
    public function isDrawn(): bool
    {
        return $this->control->getOption('rendered') === true;
    }

    /**
     * The control naming its reasons and its hint, reasons first - what went
     * wrong before what the field is for, the order they are drawn in - after
     * whatever it already named, and saying it was refused.
     */
    private function described(Html $element): Html
    {
        $named = $element->getAttribute('aria-describedby');
        $ids = is_string($named) ? preg_split('/\s+/', $named, -1, PREG_SPLIT_NO_EMPTY) : [];
        if ($ids === false) {
            $ids = [];
        }

        foreach ([$this->errorId(), $this->hintId()] as $id) {
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        $element->setAttribute('aria-describedby', $ids === [] ? null : implode(' ', $ids));
        if ($this->control->hasErrors()) {
            $element->setAttribute('aria-invalid', 'true');
        }

        return $element;
    }

    /**
     * A button as <button>, its caption as its content, which is what
     * Button::renderAsButton() makes of it - done here so that a form does not
     * have to ask for it on every button.
     */
    private function asButton(Html $element): Html
    {
        if ($element->getName() !== 'input') {
            return $element;
        }

        $caption = $element->getAttribute('value');

        return $element->setName('button')
            ->setAttribute('value', null)
            ->setText(is_scalar($caption) || $caption instanceof \Stringable ? (string) $caption : '');
    }

    /** The control's id, which its label, its reasons and its hint are all joined to it by. */
    private function id(): string
    {
        $id = $this->control->getHtmlId();
        if (!is_string($id) || $id === '') {
            throw new \LogicException(sprintf(
                '%s has no id, and a field is joined to its label, its reasons and its hint by one.',
                $this->name(),
            ));
        }

        return $id;
    }
}
