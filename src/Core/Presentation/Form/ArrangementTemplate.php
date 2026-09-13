<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

use Nette\Utils\Html;

/**
 * What the template of an arrangement is drawn with. Named, so that the
 * templates can declare it in {templateType} and be read rather than guessed
 * at.
 */
final readonly class ArrangementTemplate
{
    /**
     * Whether the sentence explaining a required field's mark belongs above
     * the fields: only where at least one field is both required and
     * labelled, and only where its label - and so its mark, drawn inside the
     * same element - can be seen. A mark nobody sees needs nothing said about
     * it (WCAG 3.3.2).
     */
    public bool $requiredNoteShown;

    /**
     * @param list<string> $errors what was said about the form as a whole
     * @param list<FormField|FormRow> $fields every field, in the order of the form: a control that is not hidden
     *     and not a button, or a row of them drawn where its first control is
     * @param list<FormField> $buttons every button, in the order of the form
     * @param bool $labelsShown whether the labels are drawn where they can be seen
     */
    public function __construct(
        public array $errors,
        public array $fields,
        public array $buttons,
        public bool $labelsShown,
    ) {
        $this->requiredNoteShown = $labelsShown && array_any(
            $fields,
            static fn(FormField|FormRow $field): bool => $field->isRequired() && $field->label() instanceof Html,
        );
    }
}
