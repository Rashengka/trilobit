<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Permissions;

use Trilobit\Core\Security\Permissions;
use Trilobit\Core\Security\Privilege;
use Trilobit\Tests\Double\Security\DemoResource;

/** A question about a resource a module brings, written the way the rule wants - and read as one about Core's is. */
final readonly class AskingAboutAModulesResource
{
    public function __construct(private Permissions $permissions) {}

    public function mayThisPersonCorrectTheLedger(): bool
    {
        return $this->permissions->isAllowed(DemoResource::Ledger, Privilege::Edit);
    }
}
