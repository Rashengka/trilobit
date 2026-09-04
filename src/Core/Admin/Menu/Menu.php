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
}
