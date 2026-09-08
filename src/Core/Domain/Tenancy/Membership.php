<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Tenancy;

use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;

/**
 * One person, holding one role, in one tenant.
 *
 * The account is global and belonging to a tenant is a relationship, which is
 * the decision this table is. An e-mail address identifies a person across the
 * whole installation, because the same person administers three shops and buys
 * from a fourth, and making them four accounts would make "signed in" mean
 * four different things.
 *
 * What follows from that is the part worth stating: a permission is never held
 * by an account, only by an account *in a tenant*. There is no way to write
 * down "may edit content" without saying where, so rights cannot seep from one
 * tenant into another by being written down in a place that has no tenant in
 * it.
 *
 * The three columns are unique together, so granting the same role twice is
 * refused by the database rather than by whoever remembers to look.
 *
 * **One kind of account may not have a row here at all.** An account that
 * administers the installation (Trilobit\Core\Domain\User\User::isLandlord())
 * is above the businesses rather than inside one, and being both would turn
 * "which scope am I asking in" into a question every calling place has to
 * answer correctly - the sort of decision this design exists to remove rather
 * than to make carefully. The refusal is in the constructor because a
 * membership is made in more than one place, and a check in one of them is a
 * check the others do not have. What it prevents is quiet: an account with a
 * foot in both scopes works, it just works with rights nobody meant it to have.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_tenant_membership')]
#[ORM\UniqueConstraint(name: 'uniq_membership', columns: ['tenant_id', 'user_id', 'role_id'])]
class Membership
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private User $user,
        #[ORM\ManyToOne(targetEntity: Role::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Role $role,
    ) {
        if ($user->isLandlord()) {
            throw new \LogicException(sprintf(
                '%s administers the installation, so it cannot also hold a role in %s. The two are different '
                    . 'scopes rather than different levels: an account above the businesses has no rights inside '
                    . 'one, and seeing what a business sees is done by taking somebody else\'s identity for a '
                    . 'while rather than by belonging to both at once.',
                $user->email(),
                $tenant->name(),
            ));
        }
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function user(): User
    {
        return $this->user;
    }

    public function role(): Role
    {
        return $this->role;
    }
}
