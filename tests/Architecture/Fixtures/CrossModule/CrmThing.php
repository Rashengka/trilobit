<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\CrossModule;

use Doctrine\ORM\Mapping as ORM;

/** Stands in for an entity of one switchable module; see CoreThing. */
#[ORM\Entity]
#[ORM\Table(name: 'crm_thing')]
class CrmThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
