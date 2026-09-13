<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Setup;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * That this installation was set up through the wizard at /_setup - one row,
 * under the one key there is, so that a second one cannot be written.
 *
 * **It is the lock and not the record of who is in charge.** Whether the
 * wizard is still open is read off the data (decision O3 in
 * .ai/plans/23-instalace-na-zelene-louce.md: open while the installation
 * holds no account and no business), and that reading is asked before anybody
 * writes - which is exactly why it cannot be what decides between two
 * visitors finishing at the same moment: both would ask, both would be told
 * "empty", and both would become the installation's administrator. What
 * decides is this row. Trilobit\Core\Setup\Installer writes it first, inside
 * the transaction that makes the account and the business, and its key is the
 * primary key: the second visitor's insert waits on the first one's row and
 * is refused when that commits, and the second visitor writes nothing at all.
 * With the row held the installer asks again whether the installation is
 * empty, with a locking read, which is what refuses a visitor finishing while
 * a command makes the first account.
 *
 * **The key is fixed rather than generated, and that is the whole mechanism.**
 * A generated key would let a second row in beside the first; ONLY cannot be
 * written twice by anybody, including a writer nobody has written yet. There
 * is no unique index over something else that could be forgotten, and no
 * nullable column for MariaDB to let in twice.
 *
 * An installation set up from the command line - `app:tenant` and
 * `app:account`, as the README describes - has no row here, and does not need
 * one: the wizard is closed by the rows those commands made, not by this.
 *
 * It is written past the entity manager (see the installer), and mapped
 * anyway, so that the table is generated from this class like every other and
 * the schema tools know it belongs to Core.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_setup_completion')]
#[Shared(because: 'setting up is done to the installation before any business exists, and belongs to none of them')]
class Completion
{
    /** The one key a completion is ever written under. */
    public const int ONLY = 1;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ONLY;

    public function __construct(
        #[ORM\Column]
        private DateTimeImmutable $completedAt,
    ) {}

    public function id(): int
    {
        return $this->id;
    }

    public function completedAt(): DateTimeImmutable
    {
        return $this->completedAt;
    }
}
