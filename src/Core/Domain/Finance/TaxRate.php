<?php

declare(strict_types=1);

namespace Trilobit\Core\Domain\Finance;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Domain\Tenancy\Tenant;

/**
 * One rate of tax a business charges, kept in the business's own list of them.
 *
 * **The list is the business's.** Every business keeps its own rates, and
 * nothing a business keeps here changes because another business changed its
 * own. A rate belongs to a business, so every read of this table is scoped by
 * Trilobit\Core\Tenancy\TenantFilter. Nothing points at a rate yet: the
 * shop's products still carry a rate of their own.
 *
 * **The rate is kept as basis points** - see VatRate - and the name beside it
 * is what a person reads when choosing one. The country - two capital letters,
 * meant as ISO 3166-1 alpha-2 and not checked against its list - and the kind
 * (TaxRateKind) say which rate of which country it is; both may be empty, for
 * a rate a business made up for itself. template_key says which rate of a
 * template the row was taken from, and is empty for every row made by hand; it
 * is unique within a business, and MariaDB holds two empty ones to be
 * different, so any number of rates may have none (see "A unique index over a
 * column that may be empty" in README.md).
 *
 * **One rate of a business is its default** - the one a new price will start
 * with. That there is at most one is the database's to hold: default_key is a
 * column the database works out as the business on the default row and as
 * nothing on every other, and it is unique, so a second default is refused
 * whoever writes it. The column is VIRTUAL for the reason the one on
 * Trilobit\Core\Domain\User\Role is, and mapped so that the schema comparison
 * generates it; it is never written from here. That the default is never
 * archived is the database's to hold too: is_default carries a CHECK that a
 * row which is the default has no archived_at, generated from its column
 * definition, so a default archived - or an archived rate made the default -
 * by a request that read the row before somebody else changed it is refused
 * as well. That there is at least one is Trilobit\Core\Finance\TaxRates' to
 * hold: the first rate of a business is made its default, and the default
 * moves only by another rate being made it.
 *
 * **A rate is archived rather than deleted.** It is no longer offered for
 * anything new, and is kept for whatever will be priced at it. Every rule
 * about when a rate may be written, archived or made the default is in
 * Trilobit\Core\Finance\TaxRates and in no method here: the methods below say
 * what changes, and the service says whether it may.
 */
#[ORM\Entity]
#[ORM\Table(name: 'core_tax_rate')]
#[ORM\UniqueConstraint(name: 'uniq_tax_rate_template', columns: ['tenant_id', 'template_key'])]
#[ORM\UniqueConstraint(name: 'uniq_tax_rate_default', columns: ['default_key'])]
class TaxRate
{
    /** As long as the label a select shows it under. */
    public const int MAX_NAME_LENGTH = 191;

    public const int MAX_TEMPLATE_KEY_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** In basis points; see VatRate. */
    #[ORM\Column]
    private int $rate;

    #[ORM\Column(length: self::MAX_TEMPLATE_KEY_LENGTH, nullable: true)]
    private ?string $templateKey = null;

    /**
     * The CHECK is written in the column's definition because that is where
     * the schema comparison generates one from; it is not compared afterwards,
     * as no column definition is (see the class).
     */
    #[ORM\Column(columnDefinition: 'TINYINT NOT NULL CHECK (is_default = 0 OR archived_at IS NULL)')]
    private bool $isDefault = false;

    /** Worked out by the database: the business on its default rate, nothing on every other. */
    #[ORM\Column(
        name: 'default_key',
        type: Types::INTEGER,
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'INT AS (IF(is_default, tenant_id, NULL)) VIRTUAL',
        generated: 'ALWAYS',
    )]
    private ?int $defaultKey = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $archivedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(
        /** Whose rate this is. Two businesses share no rate. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: self::MAX_NAME_LENGTH)]
        private string $name,
        VatRate $rate,
        /** Two capital letters, meant as ISO 3166-1 alpha-2: CZ. */
        #[ORM\Column(length: 2, nullable: true, options: ['fixed' => true])]
        private ?string $country,
        #[ORM\Column(length: 16, nullable: true, enumType: TaxRateKind::class)]
        private ?TaxRateKind $kind,
        /** Where it stands among the business's rates: lower first. */
        #[ORM\Column]
        private int $position,
        #[ORM\Column]
        private DateTimeImmutable $createdAt,
    ) {
        $this->rate = $rate->basisPoints();
        $this->updatedAt = $createdAt;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function rate(): VatRate
    {
        return new VatRate($this->rate);
    }

    public function country(): ?string
    {
        return $this->country;
    }

    public function kind(): ?TaxRateKind
    {
        return $this->kind;
    }

    public function templateKey(): ?string
    {
        return $this->templateKey;
    }

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof DateTimeImmutable;
    }

    public function archivedAt(): ?DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function position(): int
    {
        return $this->position;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** `21 % - Standard rate`: what a person chooses a rate by, the rate written as VatRate::written() writes it. */
    public function label(): string
    {
        return sprintf('%s - %s', $this->rate()->written(), $this->name);
    }

    /** Everything a person writes about the rate, the rate itself included. */
    public function revise(string $name, VatRate $rate, ?string $country, ?TaxRateKind $kind, DateTimeImmutable $at): void
    {
        $this->name = $name;
        $this->rate = $rate->basisPoints();
        $this->country = $country;
        $this->kind = $kind;
        $this->updatedAt = $at;
    }

    public function makeDefault(DateTimeImmutable $at): void
    {
        $this->isDefault = true;
        $this->updatedAt = $at;
    }

    public function stopBeingDefault(DateTimeImmutable $at): void
    {
        $this->isDefault = false;
        $this->updatedAt = $at;
    }

    public function archive(DateTimeImmutable $at): void
    {
        $this->archivedAt = $at;
        $this->updatedAt = $at;
    }

    public function restore(DateTimeImmutable $at): void
    {
        $this->archivedAt = null;
        $this->updatedAt = $at;
    }
}
