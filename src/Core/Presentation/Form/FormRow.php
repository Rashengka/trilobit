<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

use Nette\Utils\Html;

/**
 * Controls laid out beside each other as one field, under the label of the
 * first - the one exception to a field per control, the way Bootstrap has it
 * too (.ai/plans/19, step 5).
 *
 * A presenter writes one with the framework's own option rather than with
 * anything of ours: $form->addText('from', ...)->setOption('nextTo', 'until'),
 * and a chain of them for more than two. It is what
 * Nette\Forms\Rendering\DefaultFormRenderer already draws as one pair under
 * the first label, so a row is written the same way whichever renderer draws
 * the form, and there is no new word to learn for it. It is not addGroup(),
 * which is a fieldset with a legend of its own - another thing altogether.
 *
 * What it hands the template is the field of each of its controls, each still
 * the FormField it would be on its own - its own id, its own reasons and hint
 * joined to it in aria-describedby, its own required - and the one label that
 * is seen. The label of every other control is drawn out of sight rather than
 * left out, so that every control in a row keeps a name of its own for
 * somebody who cannot see which box is which.
 */
final readonly class FormRow
{
    /** @param non-empty-list<FormField> $fields every control of the row, in the order it is laid out in */
    public function __construct(public array $fields) {}

    /** The label of the row: the label of its first control, pointing at that control. */
    public function label(): ?Html
    {
        return $this->fields[0]->label();
    }

    /**
     * Whether the label of the row is marked required: where any control in
     * it is. It is the only label the eye has for the row, and one left
     * unmarked over a required control says the whole row may be left empty.
     * Which of them has to be answered is still said exactly, by each
     * control's own required attribute.
     */
    public function isRequired(): bool
    {
        return array_any($this->fields, static fn(FormField $field): bool => $field->isRequired());
    }
}
