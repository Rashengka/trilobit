<?php

declare(strict_types=1);

namespace Trilobit\Core\Presentation\Front\Signpost;

/**
 * The homepage's signpost: whatever the enabled modules contributed, in a
 * stable order.
 *
 * Ordering is by label rather than by registration order, because
 * registration order follows the order the modules happen to compile in,
 * which is not something a visitor reading the homepage can see. See
 * Trilobit\Core\Admin\Menu\Menu, which the same reasoning shaped first.
 */
final class SignpostList
{
    /** @var list<Signpost>|null */
    private ?array $items = null;

    /** @param iterable<SignpostProvider> $providers */
    public function __construct(
        private readonly iterable $providers,
    ) {}

    /** @return list<Signpost> */
    public function items(): array
    {
        if ($this->items !== null) {
            return $this->items;
        }

        $items = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->provide() as $signpost) {
                $items[] = $signpost;
            }
        }

        usort($items, static fn(Signpost $left, Signpost $right): int => $left->label <=> $right->label);

        return $this->items = $items;
    }
}
