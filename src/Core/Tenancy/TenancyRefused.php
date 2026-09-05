<?php

declare(strict_types=1);

namespace Trilobit\Core\Tenancy;

use RuntimeException;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * Something was about to be read or written without it being settled whose it
 * is, and was stopped instead.
 *
 * Every one of these is loud on purpose. The failure this whole dimension
 * exists to prevent is the quiet one: a query with no tenant in it comes back
 * with rows, they look right, and they are somebody else's. There is no
 * sensible fallback for any of the cases below - a default tenant is the
 * mix-up itself, wearing a name.
 */
final class TenancyRefused extends RuntimeException
{
    public static function noTenantEntered(): self
    {
        return new self(sprintf(
            'A tenanted table was queried before it was settled which tenant this is. Every request settles '
            . 'that from the host it arrived at, before anything is routed; a command line or a test settles '
            . 'it by entering one through %s.',
            Tenancy::class,
        ));
    }

    /** @param class-string $entity */
    public static function entityCarriesNoTenant(string $entity): self
    {
        return new self(sprintf(
            '%s has neither a "%s" association to %s nor the %s attribute, so there is no way to tell whose '
            . 'rows it holds. Give it the association if it belongs to a tenant, or the attribute with the '
            . 'reason it does not.',
            $entity,
            TenantFilter::FIELD,
            Tenant::class,
            Shared::class,
        ));
    }

    public static function unknownHost(string $host): self
    {
        return new self(sprintf(
            "No tenant answers at '%s'. A host that names no tenant is refused rather than served by a default "
            . 'one: serving it would hand one business the site of another, and would look like a working page '
            . 'while it did so.',
            $host,
        ));
    }
}
