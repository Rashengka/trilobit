<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\User;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * A named set of permissions, granted to accounts rather than to people.
 *
 * The permissions are a list of strings in one column and not a table of their
 * own. The first version has a handful of them and they are chosen by ticking
 * boxes in a single form, which a list does perfectly well.
 * **Exit condition:** a table appears the moment the number of permissions
 * outgrows one form - see .ai/plans/01c-datovy-model.md.
 *
 * The code is what everything else refers to a role by, and the name is what a
 * person reads. They are separate so that renaming a role in the
 * administration cannot invalidate whatever was written against it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_role')]
#[Shared(because: 'a role is part of the application rather than of a business, and a tenant that wants one of its own gets a row that names it - see Trilobit\Core\Domain\Tenancy\Membership')]
class Role
{
    /**
     * The code of the one role the application defines rather than a
     * business: the owner of the business, which holds the whole of the
     * application - `app:*`, every section there is and every one added later.
     *
     * It is a code rather than a flag on the row because a code is what
     * everything else already refers to a role by, and it is written here once
     * because the owner is the only role allowed to hold the whole of the
     * application: Trilobit\Core\Security\AccessComposition drops that piece on
     * any other role. A second spelling of it anywhere would be a second place
     * deciding who owns a business.
     */
    public const string OWNER = 'owner';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * @param list<string> $permissions what an account holding this role may
     *     do. Nothing in Core enforces one yet; the identity carries them so
     *     that a module can. See .ai/plans/08 decision D2.
     */
    public function __construct(
        #[ORM\Column(length: 64, unique: true)]
        private string $code,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column(type: Types::JSON)]
        private array $permissions = [],
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return $this->permissions;
    }

    /**
     * Replaces what this role is assembled from.
     *
     * It exists for the one role the application defines rather than a tenant:
     * the owner Trilobit\Core\Console\AccountCommand makes, which holds the
     * whole of the application. A row under that code may say something else -
     * edited by hand, or left by an earlier build - and a command that only
     * ever created the role would leave that account with rights nobody would
     * think to look for. Replacing rather than adding is what makes running the
     * command say what the role is now, rather than what it has ever been.
     *
     * @param list<string> $permissions
     */
    public function redefine(array $permissions): void
    {
        $this->permissions = $permissions;
    }
}
