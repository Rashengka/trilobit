<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Content;

/**
 * One group of the elements a browser hands us before any class is written:
 * what it is called, what it is for, which rules in assets/base.css make it up,
 * and the specimens the style guide has to show.
 *
 * It is the counterpart of Trilobit\Core\Presentation\Component\Component for
 * the half of the design system that has no components. A heading, a rule, a
 * block of code and a figure are not things anybody assembles; they arrive
 * already written, out of an editor or out of a template, and what the design
 * system decides about them is how they look.
 *
 * The selectors are the reason this class carries more than a name. A component
 * proves it exists by having a file; a group of elements has no file, so the
 * claim that the style guide documents something real has to be made against
 * the stylesheet itself - see tests/Template/ContentGroupRegistryTest.
 */
final readonly class ContentGroup
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
