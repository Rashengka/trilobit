<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

use Trilobit\Core\Tenancy\Tenancy;

/**
 * A menu of the site as this business has it: what the build contributes to
 * it, in the order and with the contributors the business saved
 * (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided 2026-09-13).
 *
 * The site's navigation belongs to the application, and this is where the
 * application puts it together; what contributes is whatever carries the tag
 * Trilobit\Core\DI\CoreExtension::TAG_NAVIGATION_CONTRIBUTOR, Core's own
 * contributors among them. Making links of it is the page's job - see
 * Trilobit\Core\Presentation\Front\FrontPresenter.
 *
 * **Without a business there is nobody's arrangement to read.** Every request
 * of the site is admitted to a business before a presenter runs
 * (Trilobit\Core\Tenancy\TenantFromHost), so a page drawn without one is a
 * test or a console drawing it - and what such a page can honestly draw is the
 * default from code. The contributors whose entries are one business's rows
 * say the same for themselves and hand over nothing.
 */
final readonly class Navigation
{
    /** @param iterable<NavigationContributor> $contributors */
    public function __construct(
        private iterable $contributors,
        private Menus $menus,
        private Tenancy $tenancy,
    ) {}

    public function arrangementOf(string $menu): Arrangement
    {
        $composition = $this->tenancy->isEntered() ? $this->menus->named($menu)?->composition() : null;

        return Arrangement::of($this->contributors, $composition, $menu);
    }
}
