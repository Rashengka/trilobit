<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Component;

/**
 * One entry of a signpost, with its address already resolved, ready for the
 * c-signpost component.
 *
 * It lives here rather than beside whatever contributes the entries, because
 * the same shape now feeds two different sources: the homepage's way into
 * each module (Trilobit\Core\Presentation\Front\Signpost\SignpostList) and an
 * administration section's signpost
 * (Trilobit\Core\Admin\Menu\Menu::itemsOf(), read by
 * Trilobit\Core\Presentation\Admin\AdminPresenter::signpostOf()). A page never
 * builds one from a bare href: turning a destination into one is the router's
 * job and happens in the presenter, so that a signpost pointing at a page this
 * build has no route for fails while the page is being prepared rather than
 * half-way through rendering it.
 */
final readonly class SignpostLink
{
    /**
     * @param string $summary one sentence about what is behind the link, for
     *     the places that have room for it. Empty where the source has nothing
     *     to add - a menu entry, unlike a module's own Signpost, carries no
     *     sentence to begin with.
     */
    public function __construct(
        public string $label,
        public string $href,
        public string $summary,
        public string $testId,
    ) {}
}
