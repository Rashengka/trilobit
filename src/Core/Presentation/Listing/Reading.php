<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Listing;

/**
 * What an address asked a listing to be narrowed by, as the listing's
 * filters read it: the values it will use, and a sentence for everything it
 * set aside. See Filters::read().
 */
final readonly class Reading
{
    /**
     * @param array<string, string> $values by the name of the filter
     * @param list<string> $setAside one sentence for each thing that could not be used
     */
    public function __construct(
        public array $values,
        public array $setAside,
    ) {}

    /** Whether anything narrows the list, which is what tells "nothing matches" from "nothing yet". */
    public function isFiltered(): bool
    {
        return $this->values !== [];
    }
}
