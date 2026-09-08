<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Security;

use Nette\Security\Authorizator;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * An authorizator that admits everybody, for a suite whose claim is not about
 * who may.
 *
 * It exists for exactly one situation and should be reached for in no other.
 * The pages of the administration are behind gates, so drawing one now needs
 * somebody the application would admit - and being that person means a
 * business, an account, a role and a membership, which means a schema and the
 * migrations. Trilobit\Tests\Combination\AllModuleCombinationsTest asks eight
 * builds whether they start and what their menus hold; making each of them
 * carry a database to answer that would turn the cheapest suite in the project
 * into the slowest, and it would be paying for an answer nobody asked it for.
 *
 * **What is given up is said out loud.** A build using this proves nothing
 * about admission. That claim is made where it belongs, against real rows:
 * Trilobit\Tests\Integration\Admin\AdministrationTest for the two answers a
 * gate gives, and tests/e2e for the same thing in a browser.
 *
 * The parameters are widened the way Trilobit\Core\Security\Authorizator
 * widens them, because a question is asked with the enums and a double that
 * refused them would be a double of something else.
 */
final class StandInAuthorizator implements Authorizator
{
    public function isAllowed(
        ?string $role,
        string|Resource|null $resource,
        string|Privilege|null $privilege,
    ): bool {
        return true;
    }
}
