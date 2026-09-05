<?php

declare(strict_types=1);

namespace Trilobit\Core\Contract\Content;

/**
 * The only shape a reference to a piece of content may take once it leaves the
 * module that owns it.
 *
 * It is the same idea as Trilobit\Core\Contract\Party\PartyRef and it is one
 * for the same reason: a foreign key cannot cross the boundary between two
 * switchable modules, so what travels instead is a type and an identifier with
 * no constraint behind them. `type` names the owning module's own notion of a
 * thing, namespaced by that module - `blog.article`, `catalogue.product` - and
 * `id` is that module's own identifier, kept as a string because whoever reads
 * it is not whoever minted it.
 *
 * The type is what the register of public addresses is read by, so it is also
 * what decides whether an address answers at all: a build in which no enabled
 * module claims a type has no presenter to draw it with, and the address is
 * not routed rather than routed to an error.
 */
final readonly class ContentRef
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
