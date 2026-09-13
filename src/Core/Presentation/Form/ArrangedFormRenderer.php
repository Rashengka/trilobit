<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

use Latte\Engine;
use Nette\Forms\Controls\BaseControl;
use Nette\Forms\Form;
use Nette\Forms\FormRenderer;
use Nette\Utils\Html;

/**
 * A form drawn in one of the arrangements of the design system: what every
 * arrangement does alike, with the laying out left to a template of each.
 *
 * .ai/plans/19, variant C. The three arrangements differ in how the controls
 * are laid out, and each is a Latte template that draws every label and
 * control through c-field - the same component a form written out by hand
 * uses - so the markup of one field is written once, in one file, for both
 * kinds of form. What is here is only what is not laying out:
 *
 * - which controls there are, handed to the template as FormField, each of
 *   which carries its reasons, its hint and the ids that join them to it
 *   (aria-describedby, aria-invalid); required is the framework's own
 *   attribute and comes with the control;
 * - what was said about the form as a whole;
 * - the hidden inputs, drawn after the arrangement and outside it, as
 *   Nette\Forms\Rendering\DefaultFormRenderer::renderEnd() draws them - a
 *   hidden input has no place on the page, and inside a grid or a row it
 *   would be one more thing laid out;
 * - and the refusal of a form an arrangement did not draw whole.
 *
 * That last one is loud on purpose. A control a template leaves out is not
 * sent; nothing on the page looks wrong, and the answer is lost without anybody
 * seeing it go. So every control that is not hidden has to have been drawn by
 * the time the template is done, or the form is not drawn at all.
 *
 * Groups of controls (addGroup) are not drawn as groups yet: every control is
 * a field of its own, in the order of the form. Controls laid out together on
 * one row are step 5 of the plan and will be something a FormField carries.
 * tests/Template/FormArrangementsDifferOnlyInTheirWrappingTest holds the
 * arrangements to one another.
 */
abstract class ArrangedFormRenderer implements FormRenderer
{
    public function __construct(private readonly Engine $latte) {}

    /** The template laying the controls out, by a path the engine can load. */
    abstract protected function template(): string;

    /**
     * Whether the labels are drawn where they can be seen. A label that is
     * not seen is still in the page, so a control is named either way.
     */
    protected function labelsShown(): bool
    {
        return true;
    }

    public function render(Form $form): string
    {
        $controls = [];
        foreach ($form->getControls() as $control) {
            if (!$control instanceof BaseControl) {
                throw new \LogicException(sprintf(
                    'A form drawn in an arrangement can only hold controls that draw themselves; %s does not.',
                    get_debug_type($control),
                ));
            }

            $control->setOption('rendered', false);
            $controls[] = $control;
        }

        $fields = $buttons = [];
        foreach ($controls as $control) {
            if ($control->getOption('type') === 'hidden') {
                continue;
            }

            $field = new FormField($control);
            if ($field->kind === 'button') {
                $buttons[] = $field;
            } else {
                $fields[] = $field;
            }
        }

        $body = $this->latte->renderToString($this->template(), new ArrangementTemplate(
            array_map(strval(...), $form->getOwnErrors()),
            $fields,
            $buttons,
            $this->labelsShown(),
        ));

        $undrawn = array_map(
            static fn(FormField $field): string => $field->name(),
            array_filter([...$fields, ...$buttons], static fn(FormField $field): bool => !$field->isDrawn()),
        );
        if ($undrawn !== []) {
            throw new \LogicException(sprintf(
                '%s drew no control for %s, so the form would be sent without %s.',
                $this->template(),
                implode(', ', $undrawn),
                count($undrawn) === 1 ? 'it' : 'them',
            ));
        }

        return $this->begin($form) . "\n" . $body . $this->end($form, $controls);
    }

    /**
     * The opening tag. A form sent by GET replaces the query of its address
     * with its own, so the query is taken off here and what it carried is
     * sent again as hidden inputs, in end().
     */
    private function begin(Form $form): string
    {
        $element = clone $form->getElementPrototype();
        if ($form->isMethod('get')) {
            $element->setAttribute('action', preg_replace('~\?[^#]*~', '', $this->action($form), 1));
        }

        return $element->startTag();
    }

    /**
     * Everything after the arrangement: the hidden inputs the template did not
     * draw and what the address of a form sent by GET carried, then the closing
     * tag.
     *
     * @param list<BaseControl> $controls
     */
    private function end(Form $form, array $controls): string
    {
        $hidden = '';

        if ($form->isMethod('get')) {
            $query = parse_url($this->action($form), PHP_URL_QUERY);
            $pairs = preg_split('#[;&]#', is_string($query) ? $query : '', -1, PREG_SPLIT_NO_EMPTY);
            foreach ($pairs === false ? [] : $pairs as $pair) {
                $parts = explode('=', $pair, 2);
                $name = urldecode($parts[0]);
                if (!isset($form[explode('[', $name, 2)[0]])) {
                    $hidden .= Html::el('input', ['type' => 'hidden', 'name' => $name, 'value' => urldecode($parts[1] ?? '')]);
                }
            }
        }

        foreach ($controls as $control) {
            if ($control->getOption('type') === 'hidden' && $control->getOption('rendered') !== true) {
                $hidden .= $control->getControl();
            }
        }

        return $hidden . $form->getElementPrototype()->endTag() . "\n";
    }

    private function action(Form $form): string
    {
        $action = $form->getElementPrototype()->getAttribute('action');

        return is_scalar($action) || $action instanceof \Stringable ? (string) $action : '';
    }
}
