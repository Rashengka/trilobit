<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Admin;

/** One contributor of the site's navigation, as the page arranging it lists it. */
final readonly class NavigationSourceRow
{
    public function __construct(
        public int $index,
        public string $label,
        public bool $hidden,
    ) {}
}
