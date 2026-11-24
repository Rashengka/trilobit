<?php

declare(strict_types=1);

namespace Trilobit\Shop\Domain\Product;

/**
 * Where products are kept, as the rest of the module has to know it.
 *
 * An interface here and an implementation under Infrastructure/, so that the
 * domain does not know Doctrine - the rule the Cms module's repositories keep
 * too (.ai/plans/01-architektura.md §1).
 *
 * Nothing in it names a business. Every read of shop_product is scoped by
 * Trilobit\Core\Tenancy\TenantFilter, so findBySku() answers about the SKUs of
 * the business the request is inside, which is the only one an SKU is unique
 * in.
 */
interface ProductRepository
{
    public function find(int $id): ?Product;

    /** The product of this business carrying $sku, or null. */
    public function findBySku(string $sku): ?Product;

    /** @return list<Product> by name */
    public function all(): array;

    public function save(Product $product): void;

    public function remove(Product $product): void;
}
