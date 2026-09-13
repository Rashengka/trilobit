<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Tenancy;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\PartlyShared;

/**
 * The mistake the attribute must not become a way past: a table that says some
 * of its rows are one business's, with no column saying which.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_partly_shared_bare')]
#[PartlyShared(because: 'it is a fixture standing in for a table that says so and cannot be scoped all the same')]
class PartlySharedWithoutTenant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
