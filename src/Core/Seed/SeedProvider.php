<?php

declare(strict_types=1);

namespace Trilobit\Core\Seed;

use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * A module adds what it shows to `bin/trilobit app:seed` by registering a
 * service that implements this and carries the tag
 * Trilobit\Core\DI\CoreExtension::TAG_SEED_PROVIDER.
 *
 * It is the arrangement the routes and the menu entries already use, for the
 * reason they use it: the seed is Core's, and Core holds no list of modules. A
 * module that is switched off registers no service, so a build without it is
 * seeded without its content and nothing has to know its name.
 *
 * **What a module seeds, it seeds through its own writing side** - the service
 * its administration writes with, not rows put in past it. A seed is worth
 * having only while it shows a state the application can reach; one written
 * past the application shows a state nobody can make, and it goes on showing
 * it after the application has stopped being able to.
 */
interface SeedProvider
{
    /**
     * Fills $business with what this module shows. The process is inside
     * $business while this runs, so everything written belongs to it.
     *
     * Called once for every business the seed makes. What is made should
     * differ between them by the business's name, so that a row of one turning
     * up in the other is something a person clicking through would notice.
     *
     * @return list<string> what was made, one short sentence each, for the
     *     report the command prints
     */
    public function seed(Tenant $business): array;
}
