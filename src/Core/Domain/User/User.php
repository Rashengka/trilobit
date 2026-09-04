<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Somebody who can sign in.
 *
 * Core owns identity because every build has it: a module may point a foreign
 * key at a Core table precisely because Core cannot be switched off.
 *
 * What is here is what the mechanism needs and no more. Roles, permissions and
 * everything that turns a user into an authorised one arrive with the
 * administration; this is a user as the database has to store one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_user')]
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
    ) {}

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

    public function name(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->active;
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
}
