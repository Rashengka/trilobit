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
 * instead. Someone administering the installation is in no tenant, so asking
 * about them through it would mean giving it the "no tenant" mode that its
 * refusal exists to deny. Two services with two questions is the cheaper half
 * of that trade: nothing here weakens anything there.
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
