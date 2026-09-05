<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Tenancy;

use Doctrine\ORM\Mapping as ORM;

/**
 * A host name requests arrive at, and the tenant they therefore belong to.
 *
 * One tenant answers at as many hosts as it likes and they are aliases of each
 * other - a second entrance to the same site, not a second site. Content
 * belongs to the tenant rather than to the host, so moving a firm from one
 * name to another is a row here and nothing else.
 *
 * The host is unique across the whole installation and not within a tenant.
 * Two tenants claiming one host is not a collision to be resolved at read
 * time; it is the question "whose request is this" having two answers, which
 * is exactly the silent mix-up the whole dimension exists to prevent. The
 * database refuses it instead.
 *
 * A row here is what a request is turned into a tenant by, so the lookup that
 * reads it cannot itself be scoped by tenant - see Trilobit\Core\Tenancy\
 * HostTenants, which is why that one query goes to the connection rather than
 * through the mapper. Every other reading of this table is an administrator
 * looking at their own domains, and is scoped like everything else.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_domain')]
class Domain
{
    /** What a host name can be, and therefore what the unique index has to carry. */
    public const int MAX_HOST_LENGTH = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        /** Lower case and without a port, which is the shape a request's host arrives in. */
        #[ORM\Column(length: self::MAX_HOST_LENGTH, unique: true)]
        private string $host,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
    ) {}

    public function id(): ?int
    {
        return $this->id;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }
}
