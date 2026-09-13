<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Tenancy;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Core\Tenancy\PartlyShared;

/**
 * Rows of the whole installation beside rows of one business, told apart by
 * the tenant column being empty - and therefore a column the filter can read.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_partly_shared_thing')]
#[PartlyShared(because: 'it is a fixture standing in for a table holding the application\'s rows beside each business\'s own')]
class PartlySharedThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: true)]
    public ?Tenant $tenant = null;
}
