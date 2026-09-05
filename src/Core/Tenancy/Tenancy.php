<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * Whose request this is, for as long as it lasts.
 *
 * One process serves one tenant at a time and has to be told which before it
 * reads anything. A request is told by the host it arrived at, before its path
 * is routed; a command line or a test says so outright.
 *
 * Entering a tenant does two things, and the second is easy to leave out.
 *
 * It fills in the parameter Trilobit\Core\Tenancy\TenantFilter compares
 * against, which is what scopes every query from then on.
 *
 * And it clears the object manager whenever the tenant really changes,
 * because objects already loaded belong to the tenant they were loaded for.
 * They would be handed back out of the identity map without a query - past the
 * filter, which only ever sees SQL - so a process that served one tenant and
 * then another would answer the second with rows of the first. Clearing is not
 * a precaution here; it is the same rule as the filter, applied to the one
 * place the filter cannot reach.
 *
 * There is no default tenant and no "no tenant" mode. Asking for one that was
 * never entered raises Trilobit\Core\Tenancy\TenancyRefused, because the
 * alternative - answering with everybody's rows - is indistinguishable from
 * working.
 */
final class Tenancy
{
    private ?int $tenant = null;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function enter(int $tenant): void
    {
        if ($this->tenant === $tenant) {
            return;
        }

        if ($this->tenant !== null) {
            $this->entityManager->clear();
        }

        $this->tenant = $tenant;
        $this->entityManager->getFilters()
            ->getFilter(TenantFilter::NAME)
            ->setParameter(TenantFilter::PARAMETER, $tenant, Types::INTEGER);
    }

    public function isEntered(): bool
    {
        return $this->tenant !== null;
    }

    public function current(): int
    {
        return $this->tenant ?? throw TenancyRefused::noTenantEntered();
    }

    /**
     * The tenant as something a new row can point at.
     *
     * A reference rather than a load: the row being written needs the
     * identifier and nothing else, and reading the tenant back on every save
     * would be a query nobody asked for.
     */
    public function tenant(): Tenant
    {
        return $this->entityManager->getReference(Tenant::class, $this->current())
            ?? throw TenancyRefused::noTenantEntered();
    }
}
