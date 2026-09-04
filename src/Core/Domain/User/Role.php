<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\User;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

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
class Role
{
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
}
