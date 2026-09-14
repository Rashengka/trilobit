<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Product;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Trilobit\Core\Contract\Content\ContentRef;
use Trilobit\Core\Domain\Tenancy\Tenant;
use Trilobit\Shop\Domain\Price\Money;
use Trilobit\Shop\Domain\Price\VatRate;

/**
 * Something a business sells, what it costs before tax, and the rate of tax
 * on it (.ai/plans/30-obchod-katalog-t09.md).
 *
 * **There is no address here and no category**, for the reason a page has no
 * slug: where a product answers is Core's register of public addresses, and
 * so is which categories it is filed in - a product in two categories is two
 * rows of the register, one of them its permalink (decisions R11 and R12 of
 * .ai/plans/01e-routing-a-provazani-obsahu.md). A column here saying the same
 * would be a second answer, and the two would disagree the first time one of
 * them was written without the other. ref() is the whole of the join.
 *
 * **The price is kept without tax, and the rate beside it** (decisions Q1 and
 * V1): the price with tax is worked out, never stored, so there is one number
 * to change when either of the two changes. Both are sensitive - changing
 * them is a right of its own, `app.administration.shop.price` - and so they
 * are changed by one method of their own, reprice(), rather than alongside
 * the name; what may call it is decided above it, where the right is known.
 *
 * **The SKU is optional and the business's own.** Unique within one business,
 * and not across two, each keeping its own numbering. The index is over the
 * business and the SKU, and MariaDB holds two empty SKUs to be different - so
 * any number of products may have none, which is what optional means here,
 * and the one case where that behaviour of a unique index is what is wanted
 * rather than a trap (see "A unique index over a column that may be empty" in
 * README.md). An SKU written as nothing but spaces is kept as none.
 *
 * A product belongs to a business, so every read of this table is scoped by
 * Trilobit\Core\Tenancy\TenantFilter.
 */
#[ORM\Entity]
#[ORM\Table(name: 'shop_product')]
#[ORM\UniqueConstraint(name: 'uniq_product_sku', columns: ['tenant_id', 'sku'])]
class Product
{
    /** What the register calls this kind of content, namespaced by the module that owns it. */
    public const string TYPE = 'shop.product';

    /** As long as the label the register keeps a copy of it under. */
    public const int MAX_NAME_LENGTH = 191;

    public const int MAX_SKU_LENGTH = 64;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, enumType: ProductStatus::class)]
    private ProductStatus $status = ProductStatus::Draft;

    #[ORM\Column(length: self::MAX_SKU_LENGTH, nullable: true)]
    private ?string $sku = null;

    /** The sentence or two under the name, where a list of products shows one. */
    #[ORM\Column(type: Types::TEXT)]
    private string $perex = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $description = '';

    /** In basis points; see Trilobit\Shop\Domain\Price\VatRate. */
    #[ORM\Column]
    private int $vatRate;

    public function __construct(
        /** Whose product this is. Two businesses share no product and no SKU. */
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\Column(length: self::MAX_NAME_LENGTH)]
        private string $name,
        /** Before tax. */
        #[ORM\Embedded(class: Money::class, columnPrefix: 'price_')]
        private Money $price,
        VatRate $vatRate,
        #[ORM\Column]
        private DateTimeImmutable $updatedAt,
    ) {
        $this->vatRate = $vatRate->basisPoints();
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * How the register of public addresses refers to this product; nothing
     * before it has been saved, when there would be nothing to lead to.
     */
    public function ref(): ?ContentRef
    {
        return $this->id === null ? null : new ContentRef(self::TYPE, (string) $this->id);
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sku(): ?string
    {
        return $this->sku;
    }

    public function perex(): string
    {
        return $this->perex;
    }

    public function description(): string
    {
        return $this->description;
    }

    /** What it costs before tax. */
    public function price(): Money
    {
        return $this->price;
    }

    public function vatRate(): VatRate
    {
        return new VatRate($this->vatRate);
    }

    /** What it costs with tax, worked out rather than kept; see Money::withVat(). */
    public function priceWithVat(): Money
    {
        return $this->price->withVat($this->vatRate());
    }

    public function status(): ProductStatus
    {
        return $this->status;
    }

    public function isPublished(): bool
    {
        return $this->status === ProductStatus::Published;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** Everything about the product but what it costs; see reprice() for that. */
    public function describe(string $name, ?string $sku, string $perex, string $description, DateTimeImmutable $at): void
    {
        $sku = $sku === null ? '' : trim($sku);

        $this->name = $name;
        $this->sku = $sku === '' ? null : $sku;
        $this->perex = $perex;
        $this->description = $description;
        $this->updatedAt = $at;
    }

    /** What it costs before tax and the rate of tax on it - the two sensitive things, changed together. */
    public function reprice(Money $price, VatRate $vatRate, DateTimeImmutable $at): void
    {
        $this->price = $price;
        $this->vatRate = $vatRate->basisPoints();
        $this->updatedAt = $at;
    }

    public function publish(DateTimeImmutable $at): void
    {
        $this->status = ProductStatus::Published;
        $this->updatedAt = $at;
    }

    /** Takes it out of sight without giving its addresses away. */
    public function withdraw(DateTimeImmutable $at): void
    {
        $this->status = ProductStatus::Draft;
        $this->updatedAt = $at;
    }
}
