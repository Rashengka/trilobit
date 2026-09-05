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
 * It scopes reading. Writing is scoped by the column being NOT NULL and by
 * whoever creates the row taking the tenant from Trilobit\Core\Tenancy\
 * Tenancy, because a filter is not consulted on an insert.
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

    /** @param class-string $entity */
    public static function isShared(string $entity): bool
    {
        return new ReflectionClass($entity)->getAttributes(Shared::class) !== [];
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
