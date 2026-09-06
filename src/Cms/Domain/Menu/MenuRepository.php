<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Menu;

/**
 * Where the arranged menus are kept, as the rest of the module has to know it.
 *
 * The reading method the site itself uses asks for the top of one menu rather
 * than for everything in it: what a menu looks like when it is drawn is a
 * question about one level, and an entry that is hidden is not a level at all.
 * The administration asks for everything, because arranging is the one place
 * the hidden entries have to be visible.
 */
interface MenuRepository
{
    public function find(int $id): ?MenuItem;

    /** @return list<MenuItem> everything arranged, hidden entries included, in the order they are drawn */
    public function all(): array;

    /**
     * The visible entries at the top of $menu, in the order they were
     * arranged.
     *
     * @return list<MenuItem>
     */
    public function topOf(string $menu): array;

    public function save(MenuItem $item): void;

    public function remove(MenuItem $item): void;
}
