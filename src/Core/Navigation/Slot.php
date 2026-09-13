<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

/** One entry at the top of a menu, in the place it stands in. */
final readonly class Slot
{
    /**
     * @param int $place where in the whole sequence of places this is, the
     *     ones nothing is drawn in counted too
     * @param string $contributor the key of whoever contributed the entry
     * @param string $source what the administration calls that contributor
     */
    public function __construct(
        public int $place,
        public string $contributor,
        public string $source,
        public NavigationEntry $entry,
    ) {}
}
