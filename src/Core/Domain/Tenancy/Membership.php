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
 * **One kind of account has a row here only when that is said outright.** An
 * account that administers the installation
 * (Trilobit\Core\Domain\User\User::isLandlord()) may hold a role in a business
 * as well - one person running one shop is both, and that is what a simple
 * installation is made of. What must not happen is that it holds one because
 * nobody stopped it: the constructor, which is the way for everybody else,
 * refuses such an account and names the other way, and
 * forTheInstallationsAdministrator() is that other way and takes nobody else.
 * So every place that makes a membership either never meets the question or
 * answers it in writing, and a reader can find every such answer by name.
 *
 * Being both changes nothing about how either scope is asked. In a business
 * the account is asked about as a member of it, through
 * Trilobit\Core\Security\Permissions, and nowhere else; whether it administers
 * the installation is still the flag on the account, read by
 * Trilobit\Core\Security\Landlords and never worked out from having no row
 * here. The refusal is in this class because a membership is made in more than
 * one place, and a check in one of them is a check the others do not have.
 * What it prevents is quiet: an account with a foot in both scopes works, it
 * just works with rights nobody meant it to have.
 *
 * **A role is held only where it may be.** A role of the application may be
 * held in any business; a role a business composed, in that business alone -
 * see Trilobit\Core\Domain\User\Role::mayBeHeldIn(). Both ways of making a
 * membership refuse anything else, for the same reason as above: held in
 * another business, the role would hand somebody there whatever the first one
 * put into it. A row written past this class is not trusted either - reading a
 * role in a business never reads another business's, so the membership would
 * join to nothing and grant nothing.
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
                '%s administers the installation, so it is not given a role in %s the ordinary way. Holding one as '
                    . 'well is allowed, but it is a decision rather than a side effect: say it with '
                    . '%s::forTheInstallationsAdministrator(), and only where somebody meant the installation\'s '
                    . 'administrator to work inside this business too.',
                $user->email(),
                $tenant->name(),
                self::class,
            ));
        }

        self::refuseARoleOfAnotherBusiness($tenant, $role);
    }

    /**
     * A role in $tenant for an account that administers the installation -
     * the one way such an account is given one, and the way for nobody else.
     *
     * It does not go through the constructor, because the constructor is the
     * ordinary way and refuses exactly this; the object is made the way
     * Doctrine makes one it reads back, and the three fields are set here.
     * That keeps the refusal in the constructor unconditional rather than
     * behind a parameter any caller could pass without reading what it means.
     *
     * An account that does not administer the installation is refused here in
     * turn. A way that took anybody would be the one every caller reached for
     * so as not to have to think, and the decision this exists to make visible
     * would be made everywhere without being made anywhere.
     */
    public static function forTheInstallationsAdministrator(Tenant $tenant, User $landlord, Role $role): self
    {
        if (!$landlord->isLandlord()) {
            throw new \LogicException(sprintf(
                '%s does not administer the installation, so there is nothing to decide about giving it a role in '
                    . '%s: the constructor is the way for every such account.',
                $landlord->email(),
                $tenant->name(),
            ));
        }

        self::refuseARoleOfAnotherBusiness($tenant, $role);

        $membership = new \ReflectionClass(self::class)->newInstanceWithoutConstructor();
        $membership->tenant = $tenant;
        $membership->user = $landlord;
        $membership->role = $role;

        return $membership;
    }

    private static function refuseARoleOfAnotherBusiness(Tenant $tenant, Role $role): void
    {
        if ($role->mayBeHeldIn($tenant)) {
            return;
        }

        throw new \LogicException(sprintf(
            '%s is a role of %s, so it cannot be held in %s: a role a business composed means what that business '
                . 'put into it, and nowhere else. Hold a role of %s, or one of the application.',
            $role->code(),
            $role->business()?->name() ?? 'another business',
            $tenant->name(),
            $tenant->name(),
        ));
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
