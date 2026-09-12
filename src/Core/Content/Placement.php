<?php

declare(strict_types=1);

namespace Trilobit\Core\Content;

/**
 * Where an address sits, said the way a form asks for it: which category it
 * is filed under, and the last part that is written by hand.
 *
 * It exists because an address is stored whole (decision R7) and edited in
 * two halves (decision C4), and something has to take the one apart into the
 * other. Taking it apart can fail - an address typed out in full before
 * categories existed has segments above its last one that are not a
 * category - and Trilobit\Core\Content\Categories::placementOf() answers null
 * then, rather than a placement that would move the content the moment the
 * form was saved.
 */
final readonly class Placement
{
    public function __construct(
        /** The category it is filed under, or null for an address at the root of the site. */
        public ?string $category,
        public string $segment,
    ) {}
}
