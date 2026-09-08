<?php

declare(strict_types=1);

namespace Trilobit\Core\Security;

use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Tenancy\Membership;

/**
 * Who holds which role in the tenant this request is in, and what each of those
 * roles is assembled from.
 *
 * It is the one place either of those is read, and it is a service of its own
 * for that reason rather than for tidiness. What somebody may do is asked at
 * least twice in a request - once to put the set on the identity, and again for
 * every question a page asks - so it is the read a shared cache will have to
 * stand in front of. A cache in front of one service is a decision; a cache in
 * front of two readers spread through the code is two decisions, one of which
 * will be made differently.
 * **Exit condition:** the cache and the fingerprint of decision D6, which
 * replace the queries here without changing what is asked of this class.
 *
 * **Nothing here names a tenant and nothing here takes one.** The filter over
 * core_tenant_membership puts it into the statement, which is the same sentence
 * every other read of a tenanted table says - and it is why a read attempted
 * before the tenant is settled raises Trilobit\Core\Tenancy\TenancyRefused
 * rather than answering with everybody's rows.
 *
 * What it returns is data and not objects on purpose: names and lists of names,
 * which are values a cache can hold and hand back identical - rather than
 * entities, which would come back detached from the manager that loaded them.
 */
final readonly class Memberships
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * The codes of the roles one person holds here, and nothing about any other
     * business they may belong to.
     *
     * @return list<string> sorted, so that two readings of an unchanged set
     *     read the same - which is what lets one be compared with another
     */
    public function rolesHeldBy(int $person): array
    {
        $rows = $this->entityManager
            ->createQuery(sprintf(
                'SELECT m, r FROM %s m JOIN m.role r WHERE m.user = :person',
                Membership::class,
            ))
            ->setParameter('person', $person)
            ->getResult();

        $held = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $membership) {
            if (!$membership instanceof Membership || isset($seen[$membership->role()->code()])) {
                continue;
            }

            $seen[$membership->role()->code()] = true;
            $held[] = $membership->role()->code();
        }

        sort($held);

        return $held;
    }

    /**
     * Every role somebody holds here, with the pieces it was put together out
     * of.
     *
     * Roles held by nobody are left out, and that is the tenancy of this answer.
     * A role row is shared by the whole installation - see
     * Trilobit\Core\Domain\User\Role - so the only thing that makes one a role
     * *of this business* is somebody holding it here. An access list built from
     * every row would answer about a role that means nothing here, which is one
     * name away from answering with somebody else's rights.
     *
     * A list of pairs rather than a table keyed by the code, because PHP turns
     * an array key that looks like a number into one - so a role whose code was
     * digits would come back as an int and be handed to Nette as one.
     *
     * @return list<array{code: string, permissions: list<string>}>
     */
    public function rolesHeldHere(): array
    {
        $rows = $this->entityManager
            ->createQuery(sprintf('SELECT m, r FROM %s m JOIN m.role r', Membership::class))
            ->getResult();

        $held = [];
        $seen = [];
        foreach (is_array($rows) ? $rows : [] as $membership) {
            if (!$membership instanceof Membership || isset($seen[$membership->role()->code()])) {
                continue;
            }

            $seen[$membership->role()->code()] = true;
            $held[] = [
                'code' => $membership->role()->code(),
                'permissions' => $membership->role()->permissions(),
            ];
        }

        return $held;
    }
}
