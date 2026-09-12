<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

/**
 * Every group of form controls the design system has an opinion about.
 *
 * The controls are styled by name, in @layer base of assets/base.css, and never
 * through whatever is drawn around them: the three arrangements of a form that
 * .ai/plans/19 brings (side by side, stacked, label beside control) are a layer
 * on top of the controls, and a control that looked different in one of them
 * would be three designs that drift apart without anything failing.
 * tests/Architecture/FormControlsLookTheSameWhereverTheyAreTest holds that.
 *
 * The register is what keeps the style guide honest about them, the way
 * Trilobit\Core\Presentation\Content\ContentGroupRegistry does for running
 * text: tests/Template/FormElementRegistryTest fails when a group names a
 * selector base.css does not carry, and
 * tests/Template/StyleguideShowsEveryFormElementGroupTest when a group has no
 * specimen on any page of the style guide. Every group is a page of the guide's
 * Forms group, derived from this list by
 * Trilobit\Core\Presentation\Styleguide\StyleguidePages.
 *
 * The sentence under a control - what it is for, and why it was refused - is
 * not an element a browser has, so it is drawn by c-field, and the states group
 * claims its two classes along with the states of the control it is about.
 */
final class FormElementRegistry
{
    /**
     * Every kind of input somebody types into, which is every kind but the
     * ones that are a box to tick, a button, a picker of their own or nothing
     * at all. Written as what it is not rather than as a list of what it is,
     * so that an input with no type - a line of text, to a browser - is one of
     * them, and so is a kind a browser adds after this was written.
     *
     * Inside :where() so that the rule weighs no more than a bare element, and
     * every state below outweighs it rather than depending on coming later.
     */
    public const string LINES_OF_TEXT = "input:where(:not([type='checkbox'], [type='radio'], [type='range'], "
        . "[type='color'], [type='file'], [type='submit'], [type='reset'], [type='button'], [type='image'], "
        . "[type='hidden']))";

    /** @var non-empty-list<FormElementGroup>|null */
    private ?array $groups = null;

    /** @return non-empty-list<FormElementGroup> in the order the style guide shows them */
    public function all(): array
    {
        return $this->groups ??= [
            new FormElementGroup(
                'controls',
                'The controls somebody types into or picks from: a line of text of any kind, several lines, a '
                . 'list. One rule draws all of them, so a password and a date are the same box as a name, and '
                . 'each fills the place it is put in - how wide that place is belongs to the form around it.',
                [self::LINES_OF_TEXT, 'select', 'textarea', ':is(input, textarea)::placeholder'],
                [
                    'a line of text',
                    'lines of other kinds',
                    'several lines',
                    'one of a list',
                    'several of a list',
                    'on its own, outside a field',
                ],
            ),
            new FormElementGroup(
                'checks',
                'A box to tick and a set of buttons of which one is on. The browser draws them, in the accent '
                . 'of the theme and at the size of the text beside them, and the label they are written inside '
                . 'keeps the two on one line.',
                [
                    "input:is([type='checkbox'], [type='radio'])",
                    "label:has(> input[type='checkbox'], > input[type='radio'])",
                ],
                ['checkboxes', 'radio buttons'],
            ),
            new FormElementGroup(
                'fieldsets',
                'Fields that belong together, framed, under a name that says what they are together.',
                ['fieldset', 'legend'],
                ['a group of fields'],
            ),
            new FormElementGroup(
                'states',
                'What a control says about itself: what it is for, that it was refused and why, that it cannot '
                . 'be used just now - and that it is the one being typed into, which no picture can show: tab '
                . 'into any control on these pages to see it.',
                [
                    ':is(input, select, textarea):focus-visible',
                    ":is(input, select, textarea):is([aria-invalid='true'], :user-invalid)",
                    ':is(input, select, textarea):disabled',
                    'label:has(> :disabled)',
                    '.c-field__error',
                    '.c-field__hint',
                ],
                [
                    'with a hint',
                    'refused, with the reason',
                    'checked by the browser once somebody has typed',
                    'disabled',
                ],
            ),
        ];
    }

    /** @return non-empty-list<string> */
    public function names(): array
    {
        return array_map(static fn(FormElementGroup $group): string => $group->name, $this->all());
    }
}
