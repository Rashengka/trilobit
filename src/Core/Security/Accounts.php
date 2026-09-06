<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;

/**
 * The accounts of this installation, as the two things that need them ask for
 * them: the authenticator, and the command that makes the first one.
 *
 * It is a narrow service rather than a Doctrine repository handed round,
 * because everything above it should be able to say what it wants without
 * knowing that a query is involved. There are two questions here and that is
 * the whole of the data layer identity needs; a search screen will add its own
 * when there is one.
 */
final readonly class Accounts
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    public function withEmail(string $email): ?User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
    }

    /**
     * The account behind an identity in the session.
     *
     * Null is an answer rather than a fault: an identity outlives the row it
     * was made from, so a session held open across an account being removed
     * asks for one that is no longer there.
     */
    public function withId(int $id): ?User
    {
        return $this->entityManager->getRepository(User::class)->find($id);
    }

    public function roleWithCode(string $code): ?Role
    {
        return $this->entityManager->getRepository(Role::class)->findOneBy(['code' => $code]);
    }

    /** Writes the account and, through the cascade on the association, any role created with it. */
    public function save(User $account): void
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }
}
