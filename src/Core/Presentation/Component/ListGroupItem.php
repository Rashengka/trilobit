<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * One entry of c-list-group.
 *
 * With an address it is the way somewhere and is drawn as a link; without one
 * it is a line. Current marks the one entry that is on - the page you are on,
 * in a list of links - and the badge is a short count or state drawn with
 * c-badge inside the entry, so that it is read as part of the entry's name.
 */
final readonly class ListGroupItem
{
    public function __construct(
        public string $label,
        public ?string $href = null,
        public bool $current = false,
        public ?string $badge = null,
        public ?string $testId = null,
    ) {}
}
