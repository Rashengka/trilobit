<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Nette\Security\User as SignedIn;
use Trilobit\Core\Domain\User\User;

/**
 * Whether the person making this request administers the installation itself.
 *
 * It is a class of its own, and every part of that is deliberate.
 *
 * **It is not a question for Trilobit\Core\Security\Permissions.** That service
 * takes a tenant from Trilobit\Core\Tenancy\Tenancy and has no mode without
 * one, which is the whole of what it is for: a permission question asked
 * outside a tenant would be answered with somebody else's rights, so it raises
 * instead. Administering the installation happens in no tenant, so asking
 * about it through that service would mean giving it the "no tenant" mode that
 * its refusal exists to deny. Two services with two questions is the cheaper
 * half of that trade: nothing here weakens anything there. An account that also
 * holds a role in a business does not change this - it is asked about there as
 * a member of that business, and nowhere as the installation's administrator.
 *
 * **The answer is the flag and nothing worked out from anything else.** In
 * particular it is not "belongs to no business": an account may be both, and
 * that reading would take the installation away from its administrator on the
 * day they were given a shop - quietly, as a refusal like any other.
 *
 * **There is no resource and no privilege.** Administering the installation is
 * not assembled out of pieces the way a role is - somebody either does it or
 * does not - so there is nothing for a structure to offer and nothing for a
 * role to be put together from. A pair like `installation:view` would suggest
 * there is a second, narrower one to be had, and there is not.
 *
 * **The answer comes from the row, every request.** It is not put on
 * Trilobit\Core\Security\Identity and therefore not in the session: a snapshot
 * there would keep answering yes until the person signed in again, so taking
 * the flag away would not take effect and nobody would find out until it
 * mattered. This is the same sentence that makes Permissions read memberships
 * rather than the snapshot beside them. It costs one query per request - two
 * questions in one request are one query, because the account is in the entity
 * manager by the second - and it is right the moment the row changes.
 */
final readonly class Landlords
{
    public function __construct(
        private Accounts $accounts,
        private SignedIn $signedIn,
    ) {}

    /**
     * Nobody signed in administers nothing rather than being an error: the
     * question is asked while drawing pages a visitor may not have reached
     * through a sign-in yet, and "no" is the honest answer there.
     */
    public function isLandlord(): bool
    {
        $person = $this->signedIn->getId();
        if (!$this->signedIn->isLoggedIn() || !is_int($person)) {
            return false;
        }

        $account = $this->accounts->withId($person);

        return $account instanceof User && $account->isLandlord();
    }
}
