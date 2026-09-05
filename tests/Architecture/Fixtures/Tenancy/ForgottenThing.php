<?php

declare(strict_types=1);

namespace Trilobit\Tests\Architecture\Fixtures\Tenancy;

use Doctrine\ORM\Mapping as ORM;

/**
 * An entity somebody added and thought nothing about: it holds rows of a
 * business, it carries no tenant, and it says nothing about being shared.
 *
 * This is the mistake the whole dimension is built against, and the
 * application contains none of it - so without this fixture the rule in
 * EveryTenantedEntityIsScopedTest would report nothing whether it looked or
 * not.
 *
 * These three classes are mapped and read by that test alone, and are never
 * part of the application's own mapping.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_forgotten_thing')]
class ForgottenThing
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;
}
