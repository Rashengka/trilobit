<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Styleguide;

/**
 * A heading in the menu of the style guide, and the pages under it.
 *
 * The name is the first segment of every one of its pages' addresses, which
 * is why it is a slug rather than a label; the title is what a reader sees.
 */
final readonly class StyleguideGroup
{
    /** @param non-empty-list<StyleguidePage> $pages in the order the menu lists them */
    public function __construct(
        public string $name,
        public string $title,
        public string $summary,
        public array $pages,
    ) {}
}
