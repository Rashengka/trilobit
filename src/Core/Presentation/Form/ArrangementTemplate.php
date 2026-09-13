<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

/**
 * What the template of an arrangement is drawn with. Named, so that the
 * templates can declare it in {templateType} and be read rather than guessed
 * at.
 */
final readonly class ArrangementTemplate
{
    /**
     * @param list<string> $errors what was said about the form as a whole
     * @param list<FormField> $fields every control that is not hidden and not a button, in the order of the form
     * @param list<FormField> $buttons every button, in the order of the form
     * @param bool $labelsShown whether the labels are drawn where they can be seen
     */
    public function __construct(
        public array $errors,
        public array $fields,
        public array $buttons,
        public bool $labelsShown,
    ) {}
}
