<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/** One entry at the top of the site's navigation, as the page arranging it lists it. */
final readonly class NavigationRow
{
    /**
     * @param bool $canMoveUp whether the person reading may move it, and it can move - both, so that the
     *     template draws a button exactly where a press would be accepted
     */
    public function __construct(
        public int $index,
        public string $label,
        public string $source,
        public bool $canMoveUp,
        public bool $canMoveDown,
    ) {}
}
