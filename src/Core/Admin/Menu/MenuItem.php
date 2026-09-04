<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * One entry of the administration menu.
 *
 * The destination is a presenter name and nothing else, so that Core never has
 * to know which module the entry came from, and so that an entry pointing into
 * a module that is switched off can be recognised and dropped rather than
 * rendered into a link that throws.
 */
final readonly class MenuItem
{
    public function __construct(
        public string $label,
        public string $destination,
        public int $weight = 100,
    ) {}
}
