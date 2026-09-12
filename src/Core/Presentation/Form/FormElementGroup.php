<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Form;

/**
 * One group of the controls a form is made of, as the browser hands them over:
 * what it is called, what it is for, which rules in assets/base.css make it up,
 * and the specimens the style guide has to show.
 *
 * The counterpart of Trilobit\Core\Presentation\Content\ContentGroup for the
 * elements of a form rather than of running text, and kept apart from it on
 * purpose: the two registers are checked by gates of their own, so a group of
 * form elements cannot pass by being counted in the other one.
 *
 * Like a content group it carries its selectors, because an input renders
 * whether or not anything ever styled it - the claim that the style guide
 * shows something real has to be made against the stylesheet. See
 * tests/Template/FormElementRegistryTest.
 */
final readonly class FormElementGroup
{
    /**
     * @param non-empty-list<string> $selectors the selectors assets/base.css
     *     has to carry for this group, each as it is written there
     * @param non-empty-list<string> $variants the names the style guide shows
     *     this group under; every one of them has to appear on the page
     */
    public function __construct(
        public string $name,
        public string $summary,
        public array $selectors,
        public array $variants,
    ) {}
}
