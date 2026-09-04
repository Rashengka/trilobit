<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * One component of the design system: its name, what it is for, and the
 * variants the style guide has to show.
 *
 * The name is the root CSS class, because that is the name the markup, the
 * stylesheet and this record all have to agree on. Everything else about a
 * component follows from it by a rule rather than by a second field: c-card is
 * the block `card` in the file card.latte. A rule is what lets
 * tests/Template/ComponentRegistryTest walk the directory and find the
 * component nobody registered - a field would only ever say what somebody
 * remembered to type.
 */
final readonly class Component
{
    /**
     * @param non-empty-list<string> $variants the names the style guide shows
     *     this component under; every one of them has to appear on the page.
     */
    public function __construct(
        public string $name,
        public string $summary,
        public array $variants,
    ) {}

    /** The Latte block that renders it: c-marker-list is `markerList`. */
    public function block(): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $this->bareName()))));
    }

    /** The file it lives in, relative to ComponentRegistry::DIRECTORY. */
    public function file(): string
    {
        return $this->bareName() . '.latte';
    }

    private function bareName(): string
    {
        return substr($this->name, strlen(ComponentRegistry::PREFIX));
    }
}
