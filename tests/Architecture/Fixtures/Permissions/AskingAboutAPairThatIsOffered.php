<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Permissions;

use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/** A question written the way the rule wants, about a pair the structure has. */
final readonly class AskingAboutAPairThatIsOffered
{
    public function __construct(private Permissions $permissions) {}

    public function mayThisPersonRewriteAPage(): bool
    {
        return $this->permissions->isAllowed(Resource::Content, Privilege::Edit);
    }
}
