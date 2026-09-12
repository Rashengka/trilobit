<?php

declare(strict_types=1);

namespace Trilobit\Cms\Admin;

use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\MenuProvider;

/**
 * What the Cms module puts in the administration menu.
 *
 * Three entries rather than one, because they are three jobs done at
 * different times: a page is written once, the categories it is filed under
 * are the architecture of the site and change rarely, and where things are
 * listed is rearranged whenever the site grows. A build without this module
 * registers none of them, which is why Core never has to know that any of
 * them exists - categories included, although they are Core's own: this is
 * the module that files something into them.
 */
final class CmsMenu implements MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable
    {
        yield new MenuItem('Pages', 'Cms:Admin:Page:default');
        yield new MenuItem('Categories', 'Cms:Admin:Category:default');
        yield new MenuItem('Menus', 'Cms:Admin:Menu:default');
    }
}
