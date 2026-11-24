<?php

declare(strict_types=1);

namespace Trilobit\Shop\Application\Product;

/**
 * Where a product is filed: the main category, the others it is in as well,
 * and the last part of its address, which is the same in every one of them.
 *
 * **The main category is required**, and that is decision Q9 made structural
 * rather than checked: a product is in at least one category because a filing
 * cannot be written without one. It is also the permalink (decision R12) - a
 * person chooses it, and filing a product somewhere else never moves it.
 *
 * The others are kept without the main one and without repeats, in the order
 * they were given, so that a form ticking the main category among the others
 * as well does not file the product twice in one place.
 */
final readonly class Filing
{
    /** @var list<string> the other categories, by identifier */
    public array $also;

    /**
     * @param string $main the main category, by identifier
     * @param list<string> $also
     */
    public function __construct(
        public string $main,
        array $also,
        public string $segment,
    ) {
        $this->also = array_values(array_unique(array_filter(
            $also,
            static fn(string $category): bool => $category !== $main,
        )));
    }

    /** @return non-empty-list<string> every category, the main one first */
    public function categories(): array
    {
        return [$this->main, ...$this->also];
    }
}
