<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Signpost;

/**
 * One module's public entry point, as it appears on the homepage.
 *
 * The destination is a presenter name and nothing else, so that the homepage
 * link is produced by the router rather than written out as a URL somebody
 * has to keep in sync with Trilobit\Core\Routing\RouteProvider. It is looked
 * up module by module rather than merged into the administration menu's
 * MenuItem, because the two answer different questions: this one is "where
 * does a visitor go in", the admin menu is "where does an operator work".
 */
final readonly class Signpost
{
    /**
     * @param string $summary one sentence about what is behind the link, for
     *     the places that have room for it. Optional, because a module that has
     *     nothing to add should not be made to invent something.
     */
    public function __construct(
        public string $label,
        public string $destination,
        public string $summary = '',
    ) {}
}
