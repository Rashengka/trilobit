<?php

declare(strict_types=1);

namespace Trilobit\Core\Navigation;

/**
 * Something that puts entries into a menu of the site - the way home, the
 * sections of the enabled modules, the entries somebody arranged, and one day
 * a module's categories.
 *
 * A module contributes by registering a service that implements this and
 * carries the tag Trilobit\Core\DI\CoreExtension::TAG_NAVIGATION_CONTRIBUTOR;
 * Core never names who contributes. What a contributor hands over is its own
 * entries in its own order: where its entries stand among everybody else's,
 * and whether they are drawn at all, is the business's arrangement and Core's
 * to apply (.ai/plans/10-menu-submenu-a-rozcestniky.md, M3, decided
 * 2026-09-13). See Trilobit\Core\Navigation\Arrangement.
 */
interface NavigationContributor
{
    /**
     * What an arrangement names this contributor by. It is saved in the
     * database, so it has to stay the same for as long as the contributor
     * does; a module's own prefix keeps two modules from choosing the same one.
     */
    public function key(): string;

    /** What the administration calls this contributor where the arrangement is made. */
    public function label(): string;

    /** Where it stands while nobody has arranged anything: lighter first, ties settled by the key. */
    public function weight(): int;

    /**
     * The entries this contributes to $menu, in the order it wants them in,
     * each with whatever is under it; none for a menu it has nothing for.
     *
     * @return list<NavigationEntry>
     */
    public function entriesOf(string $menu): array;
}
