<?php

declare(strict_types=1);

namespace Trilobit\Core\Seed;

use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * A module's seed that also brings people: somebody holding a role made of the
 * module's own resources (.ai/plans/30-obchod-katalog-t09.md, V3).
 *
 * Implemented by the same service that implements SeedProvider, and found on
 * it rather than by a tag of its own: people without content are nothing to
 * click through, and a second tag would be a second list to keep in step with
 * the first. Core cannot write such a role itself - it may not name a module,
 * and the module's resources are the module's - so the module says what the
 * role is made of and Core makes the account, the way it makes its own.
 */
interface SeedsMembers
{
    /**
     * Who to make in $business. The process is inside $business while this
     * runs; it is called once for every business the seed makes, before the
     * content, and what it names should differ between them by the business
     * where it says anything about it.
     *
     * @return list<SeededMember>
     */
    public function members(Tenant $business): array;
}
