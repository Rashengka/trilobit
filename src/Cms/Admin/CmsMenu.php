<?php

declare(strict_types=1);

namespace Trilobit\Cms\Admin;

use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\MenuProvider;

/**
 * What the Cms module puts in the administration menu.
 *
 * Two entries rather than one, because they are two jobs done at different
 * times: a page is written once, and where it is listed is rearranged whenever
 * the site grows. A build without this module registers neither, which is why
 * Core never has to know that either of them exists.
 */
final class CmsMenu implements MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable
    {
        yield new MenuItem('Pages', 'Cms:Admin:Page:default');
        yield new MenuItem('Menus', 'Cms:Admin:Menu:default');
    }
}
