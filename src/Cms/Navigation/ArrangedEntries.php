<?php

declare(strict_types=1);

namespace Trilobit\Cms\Navigation;

use Trilobit\Cms\Application\Page\Pages;
use Trilobit\Cms\Domain\Menu\MenuItem;
use Trilobit\Cms\Domain\Menu\MenuRepository;
use Trilobit\Cms\Domain\Menu\MenuTarget;
use Trilobit\Cms\Domain\Page\Page;
use Trilobit\Core\Content\PublicPath;
use Trilobit\Core\Domain\Navigation\Menu;
use Trilobit\Core\Navigation\Menus;
use Trilobit\Core\Navigation\NavigationContributor;
use Trilobit\Core\Navigation\NavigationEntry;
use Trilobit\Core\Tenancy\Tenancy;

/**
 * The entries somebody arranged at /admin/cms/menus, contributed to the menu
 * they were arranged into (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3).
 *
 * The site's navigation is the application's, and this module is one of the
 * things in it - the one that holds what a person put there by hand, such as
 * a page of contacts next to a shop's categories. So this hands Core its
 * entries, each with the ones under it and in the order they were given, and
 * Core decides where they stand among everybody else's.
 *
 * What a visitor could not follow is left out here: an entry somebody hid,
 * with everything under it, and an entry leading to a page nobody may see.
 * Whether this build has the page a route names is not this module's question
 * - Core asks it of every contributor alike, while the links are being made.
 *
 * Without a business there are no arranged entries of anybody's, and this says
 * so rather than asking a table that belongs to one - see
 * Trilobit\Core\Navigation\Navigation for when that happens.
 */
final readonly class ArrangedEntries implements NavigationContributor
{
    public const string KEY = 'cms.menu';

    public function __construct(
        private Menus $menus,
        private MenuRepository $entries,
        private Pages $pages,
        private Tenancy $tenancy,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'The entries arranged under Menus';
    }

    public function weight(): int
    {
        return 100;
    }

    public function entriesOf(string $menu): array
    {
        if (!$this->tenancy->isEntered()) {
            return [];
        }

        $arranged = $this->menus->named($menu);
        if (!$arranged instanceof Menu) {
            return [];
        }

        $under = [];
        foreach ($this->entries->visibleIn($arranged) as $row) {
            $under[$row->parent()?->id() ?? 0][] = $row;
        }

        return $this->entriesUnder(0, $under);
    }

    /**
     * The entries filed under the entry $parent, 0 being the top of the menu.
     *
     * Walked down from the top, so that an entry under one somebody hid - which
     * is not among the visible rows - is never reached, and neither is a loop,
     * which has no way in from the top.
     *
     * @param array<int, list<MenuItem>> $under
     *
     * @return list<NavigationEntry>
     */
    private function entriesUnder(int $parent, array $under): array
    {
        $entries = [];
        foreach ($under[$parent] ?? [] as $row) {
            $entry = $this->entryOf($row, $this->entriesUnder($row->id() ?? -1, $under));
            if ($entry instanceof NavigationEntry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /** @param list<NavigationEntry> $children */
    private function entryOf(MenuItem $row, array $children): ?NavigationEntry
    {
        $key = 'menu-' . PublicPath::normalize($row->label());

        if ($row->targetType() === MenuTarget::Page) {
            $path = $this->pathOf($row->page());

            return $path === null ? null : NavigationEntry::toPath($key, $row->label(), $path, $children);
        }

        if ($row->targetType() === MenuTarget::Url) {
            return $row->target() === '' ? null : NavigationEntry::toUrl($key, $row->label(), $row->target(), $children);
        }

        return NavigationEntry::toDestination($key, $row->label(), $row->target(), $children);
    }

    /** Where a visitor finds $page, or null when there is nothing there for them. */
    private function pathOf(?Page $page): ?string
    {
        if (!$page instanceof Page || !$page->isPublished()) {
            return null;
        }

        return $this->pages->addressOf($page);
    }
}
