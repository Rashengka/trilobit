<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

/**
 * One way a listing may be narrowed: the name the address carries it under,
 * what it is called on the form, which field of the entity it narrows, and how
 * it compares.
 *
 * It has two readers - the form that asks for the value and the query that
 * uses it - and holds what both need in one place, so that the two cannot
 * disagree about which filters there are. How it compares is its own field
 * rather than something read off the control; see Comparison.
 *
 * Made through Trilobit\Core\Presentation\Listing\Filters, which checks the
 * name and the field before a filter exists.
 */
final readonly class Filter
{
    /**
     * The most a value may hold.
     *
     * A filter narrows by something a person types, and nobody types a
     * hundred characters to find a row. The limit is the form's as well as the
     * address's, so what the form lets through the address reads.
     */
    public const int MAX_LENGTH = 100;

    /**
     * @param array<int|string, string> $choices what may be chosen, by the
     *     value the address carries; empty for a filter that is typed
     */
    public function __construct(
        public string $name,
        public string $label,
        public string $field,
        public Comparison $comparison,
        public array $choices = [],
        public string $prompt = '',
    ) {}

    /** Whether the value is chosen from $choices rather than typed. */
    public function isChoice(): bool
    {
        return $this->choices !== [];
    }
}
