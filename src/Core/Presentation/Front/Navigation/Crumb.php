<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Navigation;

/**
 * One step of the trail back up from the page a visitor is standing on.
 *
 * The trail is built from the address they arrived at rather than from the
 * permalink, which is decision R12's other half: the same product reached
 * through two categories shows two different trails, and that context is the
 * whole reason it is allowed to have two addresses at all.
 */
final readonly class Crumb
{
    public function __construct(
        public string $label,
        public string $url,
        /** True for the page the visitor is on, which a trail marks rather than links. */
        public bool $isCurrent = false,
    ) {}
}
