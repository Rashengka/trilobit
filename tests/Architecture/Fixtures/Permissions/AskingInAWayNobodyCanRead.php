<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Permissions;

use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Core\Security\Resource;

/**
 * The other mistake, and the one that would otherwise be invisible: the
 * question is asked, but not where it is written, so no reader of the source
 * can tell what pair it is about.
 *
 * The pair here happens to be one the structure offers. That is the point -
 * the rule cannot know that, and a rule that passed over what it cannot read
 * would be one that could be got round by putting the resource in a constant.
 */
final readonly class AskingInAWayNobodyCanRead
{
    private const Resource ABOUT = Resource::Content;

    public function __construct(private Permissions $permissions) {}

    public function mayThisPersonDoIt(Privilege $privilege): bool
    {
        return $this->permissions->isAllowed(self::ABOUT, $privilege);
    }
}
