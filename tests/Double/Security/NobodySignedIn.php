<?php

declare(strict_types=1);

namespace Trilobit\Tests\Double\Security;

use Nette\Security\IIdentity;
use Nette\Security\User as SignedIn;
use Nette\Security\UserStorage;

/**
 * A signed-in user that nobody is signed in as, for the one question a suite
 * without a database still has to answer.
 *
 * It is the sibling of Trilobit\Tests\Double\Security\StandInAuthorizator and
 * exists for the other half of the same trade.
 * Trilobit\Tests\Combination\AllModuleCombinationsTest asks eight builds what
 * their menus hold, and a menu is now filtered by what each entry's page says
 * about who may open it. One of those entries leads to the section of the
 * installation's own administrator, and that gate is answered by
 * Trilobit\Core\Security\Landlords - which reads the account row, every
 * request, deliberately. Answering it for real would put a schema and the
 * migrations under the cheapest suite in the project, and it would do it
 * invisibly: it passes on a developer's machine, whose default database has
 * been migrated at some point, and fails on a build server, whose has not.
 * Measured, not feared - a build pointed at an empty database renders the
 * overview as `Table 'core_user' doesn't exist`.
 *
 * So the answer is stood in for by making it never be asked: Landlords looks
 * at whether anybody is signed in before it looks anything up, and here nobody
 * ever is. **What is given up is said out loud, as it is on the authorizator:
 * a build using this proves nothing about the installation's administrator.**
 * That claim is made where it belongs, against real rows, in
 * Trilobit\Tests\Integration\Admin\AdministrationTest and in tests/e2e.
 *
 * It is a whole Nette\Security\User rather than an override of one, because
 * isLoggedIn() is final there and reads the storage: the way to say nobody is
 * signed in is to hand it a storage that says so.
 */
final class NobodySignedIn extends SignedIn
{
    public function __construct()
    {
        parent::__construct(new class implements UserStorage {
            public function saveAuthentication(IIdentity $identity): void {}

            public function clearAuthentication(bool $clearIdentity): void {}

            /** @return array{bool, ?IIdentity, ?int} */
            public function getState(): array
            {
                return [false, null, null];
            }

            public function setExpiration(?string $expire, bool $clearIdentity): void {}
        });
    }
}
