<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\CrossModule;

use Doctrine\ORM\Mapping as ORM;

/**
 * Stands in for an entity of one switchable module holding both associations
 * the rule has to tell apart: one into Core, which is allowed because Core is
 * in every build, and one into a second switchable module, which is the
 * foreign key that would leave the database inconsistent the moment somebody
 * switched that module off.
 *
 * The application contains no such association, so without this fixture the
 * rule would report nothing whether it worked or not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_thing')]
class ShopThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(targetEntity: CoreThing::class)]
    public ?CoreThing $allowed = null;

    #[ORM\ManyToOne(targetEntity: CrmThing::class)]
    public ?CrmThing $forbidden = null;
}
