<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * Every business this installation runs, for the one person who is over all of
 * them rather than inside one.
 *
 * **It needs no exception to the tenant filter and that is why it can exist at
 * all.** Trilobit\Core\Domain\Tenancy\Tenant is marked
 * Trilobit\Core\Tenancy\Shared - it is what tenancy is measured against rather
 * than something measured by it - so this reads through the mapper like any
 * other query and adds nothing to the one deliberate exception the application
 * has, which is Trilobit\Core\Tenancy\HostTenants.
 *
 * **The hosts each of them answers at are deliberately not here.**
 * core_domain *is* tenanted, so listing them beside the businesses would mean
 * a second read that steps around the filter - the same shape as HostTenants
 * and, unlike that one, not forced by the question being asked before the
 * tenant is known. An exception of that kind is made on purpose or not at all.
 * **Exit condition:** the first screen that has to show or change where a
 * business answers, which is the point at which the exception is worth
 * designing rather than slipping in.
 *
 * It is called Businesses and returns tenants, which is the same word twice:
 * `Tenant` is what the dimension is called in the model, and a business is
 * what one is to the person reading the page. Naming it after the reader is
 * what keeps that word out of the administration.
 */
final readonly class Businesses
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {}

    /**
     * @return list<Tenant> by name, so that a list of them reads the same
     *     twice and does not reorder itself as rows are added
     */
    public function all(): array
    {
        $rows = $this->entityManager
            ->createQuery(sprintf('SELECT t FROM %s t ORDER BY t.name ASC', Tenant::class))
            ->getResult();

        return array_values(array_filter(is_array($rows) ? $rows : [], static fn(mixed $row): bool => $row instanceof Tenant));
    }
}
