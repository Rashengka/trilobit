<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Trilobit\Core\Domain\User\User;

/**
 * Somebody added to a business, as Trilobit\Core\Security\People::add()
 * reports it.
 */
final readonly class Addition
{
    public function __construct(
        public User $account,
        /**
         * Whether the address already had an account - in another business -
         * which was given the role and nothing else. Saying so to whoever added
         * them is accepted (.ai/plans/31-sprava-uzivatelu.md, O2).
         */
        public bool $hadAnAccount,
        /**
         * The token of the link that sets the new account's password, to be
         * sent to it, or null when the account had one already. This is the
         * only time it exists outside the message it goes into.
         */
        public ?string $invitation,
    ) {}
}
