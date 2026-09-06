<?php

declare(strict_types=1);

namespace Trilobit\Core\Admin\Menu;

/**
 * The administration menu: whatever the enabled modules contributed, in a
 * stable order.
 *
 * Ordering is by weight and then by label rather than by registration order,
 * because registration order follows the order the modules happen to compile
 * in, which is not something a person choosing menu positions can see.
 */
final class Menu
{
    /** @var list<MenuItem>|null */
    private ?array $items = null;

    /** @param iterable<MenuProvider> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /** @return list<MenuItem> */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->provide() as $item) {
                $items[] = $item;
            }
        }

        usort(
            $items,
            static fn(MenuItem $left, MenuItem $right): int => [$left->weight, $left->label] <=> [$right->weight, $right->label],
        );

        return $this->items = $items;
    }

    /**
     * The entries of one module and no other, in the same order items()
     * produces them - the source a section's signpost draws on, so that it
     * can never hold anything the bar itself does not (decision M2 in
     * .ai/plans/10-menu-submenu-a-rozcestniky.md: one data structure, two
     * renderings).
     *
     * A module that contributed nothing gets back an empty list rather than an
     * error, which is what lets the caller turn "nothing to show" into "no
     * page" instead of into an empty one.
     *
     * @return list<MenuItem>
     */
    public function itemsOf(string $module): array
    {
        return array_values(array_filter(
            $this->items(),
            static fn(MenuItem $item): bool => $item->module() === $module,
        ));
    }
}
