<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\User;

use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * Somebody who can sign in.
 *
 * Core owns identity because every build has it: a module may point a foreign
 * key at a Core table precisely because Core cannot be switched off.
 *
 * What an account may do is read off the roles it holds rather than stored on
 * the account, so that widening a role widens every account holding it. An
 * account that kept its own copy would keep the permissions the role had on
 * the day it was granted, and nobody would find out.
 *
 * The association to Role is one-directional, from the side that asks. The
 * table it produces is the many-to-many join .ai/plans/01c-datovy-model.md
 * describes; what is missing is a collection on Role listing its holders,
 * because nothing reads one. **Exit condition:** an administration screen that
 * has to answer "who holds this role" before the role may be deleted.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_user')]
#[Shared(because: 'an account is global and belonging to a tenant is a relationship; see Trilobit\Core\Domain\Tenancy\Membership')]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Switched off rather than deleted, so that what a person did stays attributable. */
    #[ORM\Column]
    private bool $active = true;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $lastLoginAt = null;

    /**
     * How this person likes the application drawn - the theme, the light or
     * dark mode, and whatever is added to
     * Trilobit\Core\Preference\PreferenceCatalogue after them.
     *
     * One column rather than one per preference, because the list is expected
     * to grow and each of those would otherwise be a migration; and on the
     * account rather than in a table of its own, because a preference has no
     * life apart from the person holding it.
     *
     * Only what somebody actually chose is in here. A preference that is absent
     * means "no opinion", which is what lets a profile take over the choice a
     * device was carrying when its owner signs in for the first time (decision
     * D8).
     *
     * Nullable so that the column could be added to a table that already had
     * rows without a data step: null is what a row written before the column
     * existed says, and "nothing chosen" is exactly what that means.
     *
     * @var array<string, string>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $preferences = null;

    /**
     * Cascaded on persist so that saving an account saves the roles created
     * alongside it; a role that already exists is an object the entity manager
     * is already holding and is not written twice.
     *
     * @var Collection<int, Role>
     */
    #[ORM\ManyToMany(targetEntity: Role::class, cascade: ['persist'])]
    #[ORM\JoinTable(name: 'core_user_role')]
    private Collection $roles;

    public function __construct(
        /** Kept apart from the identifier so that a person can change their address without becoming somebody else. */
        #[ORM\Column(length: 255, unique: true)]
        private string $email,
        #[ORM\Column(length: 255)]
        private string $passwordHash,
        #[ORM\Column(length: 255)]
        private string $name,
        #[ORM\Column]
        private DateTimeImmutable $createdAt,
    ) {
        $this->roles = new ArrayCollection();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function passwordHash(): string
    {
        return $this->passwordHash;
    }

    public function changePassword(string $passwordHash): void
    {
        $this->passwordHash = $passwordHash;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rename(string $name): void
    {
        $this->name = $name;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /** The way an account is taken away: it stops being able to sign in and stays attributable. */
    public function deactivate(): void
    {
        $this->active = false;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function lastLoginAt(): ?DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function signedIn(DateTimeImmutable $at): void
    {
        $this->lastLoginAt = $at;
    }

    /**
     * What this person chose about the way the application is drawn, and
     * nothing they were merely given.
     *
     * @return array<string, string>
     */
    public function preferences(): array
    {
        return $this->preferences ?? [];
    }

    /**
     * Neither the name nor the value is checked here. What may be preferred is
     * a fact about the build rather than about the person, and it is answered
     * in one place - Trilobit\Core\Preference\PreferenceCatalogue - which also
     * has to answer it for values that are already stored. A second copy of the
     * rule on the entity would be a second copy to keep in step.
     */
    public function prefer(string $name, string $value): void
    {
        $this->preferences = [...$this->preferences ?? [], $name => $value];
    }

    /**
     * Granting a role the account already holds does nothing. The comparison is
     * by code rather than by object, because two reads of the same row are two
     * objects and the administration would otherwise show the role twice.
     */
    public function grant(Role $role): void
    {
        if (in_array($role->code(), $this->roleCodes(), true)) {
            return;
        }

        $this->roles->add($role);
    }

    /** @return list<Role> */
    public function roles(): array
    {
        return array_values($this->roles->toArray());
    }

    /** @return list<string> sorted, so that two accounts holding the same roles read the same */
    public function roleCodes(): array
    {
        $codes = array_map(static fn(Role $role): string => $role->code(), $this->roles());
        sort($codes);

        return $codes;
    }

    /** @return list<string> every permission of every role, each one once, sorted */
    public function permissions(): array
    {
        $permissions = [];
        foreach ($this->roles() as $role) {
            foreach ($role->permissions() as $permission) {
                $permissions[$permission] = true;
            }
        }

        $names = array_keys($permissions);
        sort($names);

        return $names;
    }
}
