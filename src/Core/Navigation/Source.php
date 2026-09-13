<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

/** One contributor of a menu, as the place its arrangement is made lists it. */
final readonly class Source
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $hidden,
    ) {}
}
