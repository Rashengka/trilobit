<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Attribute;

/**
 * Says that this table holds rows of the whole installation beside rows of
 * each business, told apart by the tenant column being empty - and why.
 *
 * It sits between the two answers an entity otherwise gives. A tenanted entity
 * is scoped to the business the request is in; one carrying
 * Trilobit\Core\Tenancy\Shared is not scoped at all. This one is read with the
 * rows of the business the request is in and the rows of none, and before a
 * business is settled with the rows of none alone - those are everybody's, so
 * there is nothing in them to leak, and refusing them would stop the
 * application reading what it defines itself. See
 * Trilobit\Core\Tenancy\TenantFilter.
 *
 * It is no way past the rule that every entity is scoped. The rows that belong
 * to a business are still told apart by the tenant association, so an entity
 * declaring this without one is reported exactly as an entity nobody thought
 * about - see Trilobit\Tests\Architecture\EveryTenantedEntityIsScopedTest.
 *
 * Like Shared, it carries the reason in the same breath, because it is a
 * decision about who may read what and a reviewer needs to see it made.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class PartlyShared
{
    public function __construct(
        /** Why some of this table's rows belong to no business, in a sentence. */
        public string $because,
    ) {}
}
