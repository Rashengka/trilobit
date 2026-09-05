<?php

declare(strict_types=1);

namespace Trilobit\Cms\Domain\Marker;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * The one table the Cms module owns until it owns real ones.
 *
 * A module that maps no entity registers no mapping, contributes no migration
 * and leaves no table behind, which means the thing this project has to prove
 * - that switching a module off leaves its tables untouched and switching it
 * back on brings them back - could not be proved at all. So each module has a
 * table from the day the data layer exists, and the mechanism is tested on the
 * mechanism rather than on a half-built catalogue.
 *
 * A row says nothing more than that this module's schema was installed.
 *
 * It goes away, together with its migration, once the module has an entity of
 * its own; the tests that stand on it move to that entity in the same change.
 */
#[ORM\Entity]
#[ORM\Table(name: 'cms_marker')]
#[Shared(because: 'a row says this module\'s schema was installed, which is a fact about the installation and about no tenant')]
class Marker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column]
        private DateTimeImmutable $installedAt,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function installedAt(): DateTimeImmutable
    {
        return $this->installedAt;
    }
}
