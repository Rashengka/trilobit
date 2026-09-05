<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Tenancy\Domain;

/**
 * The one question that has to be answered before the tenant is known: which
 * tenant answers at this host.
 *
 * It goes to the connection rather than through the object-relational mapper,
 * and that is the whole reason this class exists rather than a repository.
 * Every read of a tenanted table is scoped by Trilobit\Core\Tenancy\
 * TenantFilter, and core_domain is a tenanted table like any other - an
 * administrator sees their own domains and nobody else's. But this particular
 * read is what settles the tenant in the first place, so it cannot be scoped
 * by one. Doing it as SQL keeps that exception to a single statement in a
 * single class, instead of a switch on the filter that anything could reach
 * for.
 *
 * The table and the columns come out of the mapping rather than being written
 * down here, so a renamed column is a renamed column and not a query that
 * quietly stops matching.
 */
final readonly class HostTenants
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /** Which tenant answers at $host, or null when nobody does. */
    public function tenantAt(string $host): ?int
    {
        $metadata = $this->entityManager->getClassMetadata(Domain::class);

        $found = $this->entityManager->getConnection()->fetchOne(
            sprintf(
                'SELECT %s FROM %s WHERE %s = ?',
                $metadata->getSingleAssociationJoinColumnName(TenantFilter::FIELD),
                $metadata->getTableName(),
                $metadata->getColumnName('host'),
            ),
            [strtolower($host)],
        );

        return is_int($found) || is_string($found) ? (int) $found : null;
    }
}
