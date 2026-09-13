<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;
use ReflectionClass;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * Puts the tenant into every query over a table that belongs to one.
 *
 * The failure this exists to prevent is the quiet kind. A query that forgot
 * the tenant does not fail; it returns rows, and they are somebody else's.
 * Nothing about the answer says so - not its shape, not its size, not the log.
 * So the tenant cannot be something each query remembers to add: it has to be
 * something no query can leave out.
 *
 * Two things make that true rather than likely.
 *
 * **The default is deny.** An entity is tenanted unless it carries
 * Trilobit\Core\Tenancy\Shared, so a new entity that nobody thought about is
 * one this filter demands a tenant column of - and says so, loudly, rather
 * than quietly leaving its rows unscoped. Trilobit\Tests\Architecture\
 * EveryTenantedEntityIsScopedTest asks the same question of every mapped
 * entity before anything is ever queried, so the answer arrives at build time.
 *
 * **No tenant is an error, not an empty constraint.** Reading before the
 * tenant is settled raises Trilobit\Core\Tenancy\TenancyRefused. A filter that
 * stood down when it had nothing to compare against would be a filter that is
 * absent exactly when it matters, and the request would look perfectly
 * healthy.
 *
 * **A table can hold the installation's rows beside each business's own.** It
 * says so with Trilobit\Core\Tenancy\PartlyShared, and its tenant column is
 * empty on the rows of no business. In a business it is read with that
 * business's rows and the rows of none; before a business is settled, with the
 * rows of none alone. That is not the filter standing down: it still compares,
 * and what it lets through is only what belongs to nobody in particular. Such a
 * table still needs its tenant column, and an entity declaring itself partly
 * shared without one is refused like any other.
 *
 * It scopes reading. Writing is scoped by the column being NOT NULL and by
 * whoever creates the row taking the tenant from Trilobit\Core\Tenancy\
 * Tenancy, because a filter is not consulted on an insert. On a partly shared
 * table the column may be empty, so there it is whoever creates a row that
 * says which business it is, or that it is none's.
 */
final class TenantFilter extends SQLFilter
{
    /** The name the filter is registered and enabled under in config/common.neon. */
    public const string NAME = 'tenant';

    /** The parameter Trilobit\Core\Tenancy\Tenancy fills in when a tenant is entered. */
    public const string PARAMETER = 'tenant';

    /** The association every tenanted entity carries, and therefore the column this compares. */
    public const string FIELD = 'tenant';

    /**
     * @param ClassMetadata<object> $targetEntity
     */
    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if (self::isShared($targetEntity->getName())) {
            return '';
        }

        if (self::isPartlyShared($targetEntity->getName())) {
            return $this->withTheRowsOfNoBusiness($targetEntity, $targetTableAlias);
        }

        if (!$this->hasParameter(self::PARAMETER)) {
            throw TenancyRefused::noTenantEntered();
        }

        return sprintf(
            '%s.%s = %s',
            $targetTableAlias,
            self::tenantColumnOf($targetEntity),
            $this->getParameter(self::PARAMETER),
        );
    }

    /**
     * Whether $entity is one table for everybody.
     *
     * An entity carrying Trilobit\Core\Tenancy\PartlyShared as well is not:
     * of two declarations that disagree, the one that scopes more is the one
     * taken, so that a stray attribute can only ever narrow what is read.
     *
     * @param class-string $entity
     */
    public static function isShared(string $entity): bool
    {
        $class = new ReflectionClass($entity);

        return $class->getAttributes(Shared::class) !== [] && $class->getAttributes(PartlyShared::class) === [];
    }

    /** @param class-string $entity */
    public static function isPartlyShared(string $entity): bool
    {
        return new ReflectionClass($entity)->getAttributes(PartlyShared::class) !== [];
    }

    /**
     * The rows of the business the process is in and the rows of none, or the
     * rows of none alone before a business is settled.
     *
     * @param ClassMetadata<object> $entity
     */
    private function withTheRowsOfNoBusiness(ClassMetadata $entity, string $alias): string
    {
        $column = $alias . '.' . self::tenantColumnOf($entity);

        if (!$this->hasParameter(self::PARAMETER)) {
            return sprintf('%s IS NULL', $column);
        }

        return sprintf('(%1$s = %2$s OR %1$s IS NULL)', $column, $this->getParameter(self::PARAMETER));
    }

    /**
     * The column carrying the tenant of $entity, or a refusal naming what is
     * missing.
     *
     * The column is read out of the mapping rather than written down here, so
     * that a naming strategy nobody in this project chose by hand cannot make
     * the filter compare a column that does not exist.
     *
     * @param ClassMetadata<object> $entity
     */
    public static function tenantColumnOf(ClassMetadata $entity): string
    {
        if (!$entity->hasAssociation(self::FIELD) || !$entity->isSingleValuedAssociation(self::FIELD)) {
            throw TenancyRefused::entityCarriesNoTenant($entity->getName());
        }

        if ($entity->getAssociationTargetClass(self::FIELD) !== Tenant::class) {
            throw TenancyRefused::entityCarriesNoTenant($entity->getName());
        }

        return $entity->getSingleAssociationJoinColumnName(self::FIELD);
    }
}
