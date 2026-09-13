<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * Core's entry for arranging the site's navigation. A business's own section
 * rather than the installation's - see
 * Trilobit\Core\Presentation\Installation\SignpostPresenter, whose signpost is
 * made of the installation's entries and not of everything Core contributes.
 */
final class NavigationMenu implements MenuProvider
{
    public function provide(): iterable
    {
        yield new MenuItem('Navigation', 'Core:Admin:Navigation:default');
    }
}
