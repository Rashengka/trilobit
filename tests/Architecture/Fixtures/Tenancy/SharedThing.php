<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Tenancy;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * An entity that says out loud it is one table for the whole installation, so
 * that the rule can be seen to accept the declared way out and only that.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_shared_thing')]
#[Shared(because: 'it is a fixture standing in for a table that really is the same for everybody')]
class SharedThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
