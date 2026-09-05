<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Tenancy;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * An entity that was given the dimension, so that the rule reporting the one
 * beside it is a rule that told them apart rather than one that reports
 * everything it is shown.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_tenanted_thing')]
class TenantedThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    public Tenant $tenant;
}
