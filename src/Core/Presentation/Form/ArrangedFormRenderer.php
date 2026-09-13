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
 * Controls laid out beside each other under the label of the first - a row,
 * written with the framework's own nextTo option - are handed over as one
 * FormRow, drawn where its first control is (see FormRow). A row that cannot
 * be drawn as one is refused as loudly, by the name of the control that is
 * wrong. The groups addGroup() makes are not drawn as groups: every control
 * of one is a field, in the order of the form.
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

        $rows = $this->rows($form, $controls);
        $laterInARow = [];
        foreach ($rows as $row) {
            foreach (array_slice($row, 1) as $member) {
                $laterInARow[spl_object_id($member)] = true;
            }
        }

        $fields = $buttons = $drawn = [];
        foreach ($controls as $control) {
            if ($control->getOption('type') === 'hidden' || isset($laterInARow[spl_object_id($control)])) {
                continue;
            }

            $row = $rows[spl_object_id($control)] ?? null;
            if ($row !== null) {
                $members = array_map(static fn(BaseControl $member): FormField => new FormField($member), $row);
                $fields[] = new FormRow($members);
                array_push($drawn, ...$members);

                continue;
            }

            $field = new FormField($control);
            $drawn[] = $field;
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
            array_filter($drawn, static fn(FormField $field): bool => !$field->isDrawn()),
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
     * The rows the controls are laid out in, by their first control: a
     * control whose nextTo option names another, followed along as far as
     * the chain goes, the way DefaultFormRenderer::renderControl() follows it.
     *
     * Each of these is refused rather than drawn somehow: a row naming
     * something that is not a control of the form; a hidden input or a button
     * in a row - the one has no place on the page, the others have a row of
     * their own; a control two others are to be next to, which would be drawn
     * twice; and controls next to one another in a circle, none of which is
     * first. The framework's renderer draws the second-to-last twice and loops
     * for ever on the last.
     *
     * @param list<BaseControl> $controls
     * @return array<int, non-empty-list<BaseControl>> every row, by the spl_object_id() of its first control
     */
    private function rows(Form $form, array $controls): array
    {
        $next = $previous = [];
        foreach ($controls as $control) {
            $name = $control->getOption('nextTo');
            if ($name === null) {
                continue;
            }

            $after = is_string($name) ? $form->getComponent($name, false) : null;
            if (!$after instanceof BaseControl || !in_array($after, $controls, true)) {
                throw new \LogicException(sprintf(
                    '%s is to be next to %s, which is not a control of the form.',
                    $control->getName(),
                    is_scalar($name) ? (string) $name : get_debug_type($name),
                ));
            }

            foreach ([$control, $after] as $member) {
                if (in_array($member->getOption('type'), ['hidden', 'button'], true)) {
                    throw new \LogicException(sprintf(
                        '%s cannot be laid out in a row: a hidden input has no place on the page, and the buttons '
                        . 'have a row of their own.',
                        $member->getName(),
                    ));
                }
            }

            $already = $previous[spl_object_id($after)] ?? null;
            if ($already !== null) {
                throw new \LogicException(sprintf(
                    '%s is to be next to both %s and %s, and can be drawn in one row only.',
                    $after->getName(),
                    $already->getName(),
                    $control->getName(),
                ));
            }

            $next[spl_object_id($control)] = $after;
            $previous[spl_object_id($after)] = $control;
        }

        // Every control has one before it at most and one after it at most,
        // so the rows are the chains starting at a control with none before
        // it - and what is left over is a circle.
        $rows = $inARow = [];
        foreach ($controls as $control) {
            if (!isset($next[spl_object_id($control)]) || isset($previous[spl_object_id($control)])) {
                continue;
            }

            $row = [$control];
            for ($at = $control; isset($next[spl_object_id($at)]);) {
                $at = $next[spl_object_id($at)];
                $row[] = $at;
            }

            foreach ($row as $member) {
                $inARow[spl_object_id($member)] = true;
            }
            $rows[spl_object_id($control)] = $row;
        }

        $circle = array_filter(
            $controls,
            static fn(BaseControl $control): bool => isset($next[spl_object_id($control)]) && !isset($inARow[spl_object_id($control)]),
        );
        if ($circle !== []) {
            throw new \LogicException(sprintf(
                '%s are each to be next to another of them in a circle, so none of them is first in the row.',
                implode(', ', array_map(static fn(BaseControl $control): string => (string) $control->getName(), $circle)),
            ));
        }

        return $rows;
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
