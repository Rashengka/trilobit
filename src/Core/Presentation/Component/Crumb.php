<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * One step up from the page being drawn, for c-breadcrumb: what the page above
 * is called and where it is.
 *
 * Only the steps up are crumbs. The page itself is not one, because it is the
 * one entry of the trail that is not a link - a crumb always carries an
 * address, so a trail cannot end in a link to where you already are, and the
 * component is handed the current page's name on its own.
 *
 * The address is resolved by the presenter, the same way SignpostLink's is: a
 * step up to a page this build has no route for fails while the page is being
 * prepared rather than drawing a link that leads nowhere.
 */
final readonly class Crumb
{
    public function __construct(
        public string $label,
        public string $href,
        public ?string $testId = null,
    ) {}
}
