<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\User;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Tenancy\Shared;

/**
 * A link somebody sets the password of an account with - sent to whoever was
 * added to a business and has none yet.
 *
 * **It is a key, and it is kept the way a key is.** Whoever holds the link may
 * set the password of an account they have never signed in to. What is stored
 * is therefore a digest of the token and never the token itself, and a copy of
 * this table is not a set of links anybody could open. The token exists in two
 * places only, the message it was sent in and the address somebody opens.
 *
 * **It opens once, for a week, and only while it is the newest.** Used, it is
 * marked rather than deleted, and so is one a newer link took the place of -
 * two moments worth being able to tell apart later, and two ways a link stops
 * opening that the person holding it is not told apart; see
 * Trilobit\Core\Security\PasswordLinks, which is where all three conditions
 * are asked, in one statement.
 *
 * The rows are never read as entities to decide anything: whether a link still
 * opens is asked of the database, so that two requests spending one link at
 * the same moment cannot both see it unused.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_password_link')]
#[Shared(because: 'a link sets the password of an account, and an account belongs to no business - see Trilobit\Core\Domain\User\User')]
class PasswordLink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** When the link set a password; a link opens once. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $usedAt = null;

    /** When a newer link for the same account took this one's place. */
    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $supersededAt = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: false)]
        private User $account,
        /**
         * SHA-256 of the token, in hex: a digest rather than a slow hash,
         * because a token is random enough that a slow one would add nothing.
         * The column is token_hash, which says what it holds to whoever reads
         * the table rather than the class.
         */
        #[ORM\Column(name: 'token_hash', length: 64, unique: true)]
        private string $digest,
        #[ORM\Column]
        private DateTimeImmutable $createdAt,
        #[ORM\Column]
        private DateTimeImmutable $expiresAt,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function account(): User
    {
        return $this->account;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function usedAt(): ?DateTimeImmutable
    {
        return $this->usedAt;
    }

    public function supersededAt(): ?DateTimeImmutable
    {
        return $this->supersededAt;
    }
}
