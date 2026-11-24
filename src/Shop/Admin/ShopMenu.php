<?php

declare(strict_types=1);

namespace Trilobit\Shop\Admin;

use Trilobit\Core\Admin\Menu\MenuItem;
use Trilobit\Core\Admin\Menu\MenuProvider;

/**
 * What the Shop module puts in the administration menu: the catalogue, now
 * that it has an administration page to lead to.
 *
 * The categories products are filed in are not here. They are Core's, and
 * they are arranged by whoever arranges the content of the site - one list of
 * them for the pages and the products alike (.ai/plans/11-cms-po-prvnim-proklikani.md,
 * C4); where that list lives in the administration is decision Q5 of
 * .ai/plans/30-obchod-katalog-t09.md.
 */
final class ShopMenu implements MenuProvider
{
    /** @return iterable<MenuItem> */
    public function provide(): iterable
    {
        yield new MenuItem('Products', 'Shop:Admin:Product:default');
    }
}
