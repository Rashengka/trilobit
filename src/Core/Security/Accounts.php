<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Trilobit\Core\Domain\User\Role;
use Trilobit\Core\Domain\User\User;

/**
 * The accounts of this installation, as the two things that need them ask for
 * them: the authenticator, and the command that makes the first one.
 *
 * It is a narrow service rather than a Doctrine repository handed round,
 * because everything above it should be able to say what it wants without
 * knowing that a query is involved. What is here is the whole of the data
 * layer identity needs; a search screen will add its own when there is one.
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

    /**
     * The application's role under $code - never a business's, whichever
     * business the process is in.
     *
     * A code is looked up only among the application's roles because only
     * there does it mean the same thing everywhere. A business's role under the
     * same code is that business's own; found here, it would be handed to
     * whoever asked - the command that makes an owner, for one - as if it were
     * everybody's.
     */
    public function applicationRole(string $code): ?Role
    {
        return $this->entityManager->getRepository(Role::class)->findOneBy(['code' => $code, 'tenant' => null]);
    }

    /**
     * The application's role under $code, made - granting nothing, and called
     * $name - when there is none yet. Made once, even when two runs ask at the
     * same moment.
     *
     * **Asking first and inserting afterwards is not enough on its own.** Two
     * runs - two deployments, two browser suites preparing their accounts -
     * both find no row, both insert one, and the code is unique among the
     * application's roles, so the second insert is refused. That refusal is the
     * answer rather than a fault: it means another run made the role in
     * between, and the row it made is the one to take. So the refusal is caught
     * and the role asked for again.
     *
     * It is unique among the application's roles only because the index is not
     * over the business column, whose NULL MariaDB lets in twice - see
     * Trilobit\Core\Domain\User\Role. The row goes in without a business, which
     * is what makes it the application's.
     *
     * The insert goes past the entity manager on purpose. A flush that fails
     * closes the entity manager for good, so the refusal could only be caught
     * after nothing was left to read the role back with; a statement on the
     * connection leaves it open. The table and column names are still the
     * mapping's, read off it rather than written out a second time.
     *
     * The unique index is what makes this safe, rather than a lock every writer
     * would have to remember to take: it holds for a writer nobody has written
     * yet as much as for this one.
     */
    public function applicationRoleMadeIfMissing(string $code, string $name): Role
    {
        $role = $this->applicationRole($code);
        if ($role instanceof Role) {
            return $role;
        }

        $mapping = $this->entityManager->getClassMetadata(Role::class);
        $permissions = $mapping->getColumnName('permissions');

        try {
            $this->entityManager->getConnection()->insert(
                $mapping->getTableName(),
                [$mapping->getColumnName('code') => $code, $mapping->getColumnName('name') => $name, $permissions => []],
                [$permissions => Types::JSON],
            );
        } catch (UniqueConstraintViolationException) {
            // Another run made it between the question above and this insert.
        }

        return $this->applicationRole($code) ?? throw new LogicException(sprintf(
            'The role %s was inserted, or refused because it was already there, and still cannot be read back. '
            . 'A transaction opened before it was made would see it that way; this is not meant to run inside one.',
            $code,
        ));
    }

    /** Writes the account and, through the cascade on the association, any role created with it. */
    public function save(User $account): void
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();
    }
}
