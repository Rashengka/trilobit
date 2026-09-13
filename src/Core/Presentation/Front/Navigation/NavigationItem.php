<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Navigation;

/**
 * One entry of the primary navigation, ready to be rendered.
 *
 * The address is already a URL rather than a presenter name, because the router
 * is the only thing that may turn one into the other and it has done so by the
 * time a template sees this. Trilobit\Core\Presentation\Front\Signpost\Signpost
 * is the other half of the pair: it is what a module contributes, this is what
 * the page draws.
 *
 * An entry may hold entries of its own, and it keeps its own address when it
 * does. That is the case a submenu most often breaks - a section's categories
 * sit under the section's entry, and that entry still has to lead to the
 * section's own front page - so the shape has no room for the other kind: there
 * is no entry that only opens what is under it.
 * The tree is as deep as whoever built it made it; how much of it is drawn is
 * the theme's business (c-nav, .ai/plans/10-menu-submenu-a-rozcestniky.md, M1).
 */
final readonly class NavigationItem
{
    /**
     * @param list<NavigationItem> $children the entries under this one, in the
     *     order they are drawn; none for an entry that is only a link
     */
    public function __construct(
        public string $label,
        public string $href,
        public bool $current,
        public string $testId,
        public array $children = [],
    ) {}
}
