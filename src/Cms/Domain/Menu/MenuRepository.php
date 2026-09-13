<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Menu;

use Trilobit\Core\Domain\Navigation\Menu;

/**
 * Where the arranged menus are kept, as the rest of the module has to know it.
 *
 * The reading method the site itself uses asks for the visible entries of one
 * menu, every level of it: the site draws the tree, and an entry that is
 * hidden is not part of it. The administration asks for everything, because
 * arranging is the one place the hidden entries have to be visible.
 */
interface MenuRepository
{
    public function find(int $id): ?MenuItem;

    /** @return list<MenuItem> everything arranged, hidden entries included, in the order they are drawn */
    public function all(): array;

    /**
     * The visible entries of $menu at every level, in the order they were
     * arranged among their siblings.
     *
     * @return list<MenuItem>
     */
    public function visibleIn(Menu $menu): array;

    public function save(MenuItem $item): void;

    public function remove(MenuItem $item): void;
}
