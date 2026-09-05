<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Attribute;

/**
 * Says that this entity is the same table for every tenant, and why.
 *
 * Everything is tenanted unless it says otherwise. That direction is the whole
 * point: the mistake this project has to be safe from is an entity that holds
 * one business's rows and was never given the column saying whose they are,
 * and forgetting is the ordinary way that happens. With the default the other
 * way round, forgetting produces a table that quietly answers with everybody's
 * rows; with it this way round, forgetting produces a red test - see
 * Trilobit\Tests\Architecture\EveryTenantedEntityIsScopedTest.
 *
 * So this attribute is the only way out, it has to be written on purpose, and
 * it carries the reason in the same breath, because the reason is what a
 * reviewer needs and what the author has to have thought about.
 *
 * A module may declare its own without Core knowing about it, which is why
 * this is an attribute on the entity rather than a list Core keeps.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Shared
{
    public function __construct(
        /** Why this table is one table for the whole installation, in a sentence. */
        public string $because,
    ) {}
}
