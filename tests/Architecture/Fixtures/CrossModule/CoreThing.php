<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\CrossModule;

use Doctrine\ORM\Mapping as ORM;

/**
 * Stands in for an entity of Core, which is the one thing every module is
 * allowed to point a foreign key at, because Core is in every build.
 *
 * These three classes are mapped and read by NoCrossModuleAssociationTest and
 * are never part of the application's own mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_thing')]
class CoreThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
