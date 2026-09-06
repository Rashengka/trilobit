<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Permissions;

use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The mistake the rule exists for: a question spelled perfectly well about a
 * pair src/Core/Security/permissions.neon does not have.
 *
 * Nothing would report it by itself. No role can be assembled out of a pair
 * that is not offered, so the answer is no for everybody and looks exactly
 * like a decision that somebody may not do this.
 */
final readonly class AskingAboutAPairNobodyOffers
{
    public function __construct(private Permissions $permissions) {}

    public function mayThisPersonRedirectAnAccount(): bool
    {
        return $this->permissions->isAllowed(Resource::Account, Privilege::ForceRedirect);
    }
}
